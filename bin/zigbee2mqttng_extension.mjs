// Zigbee2MqttNG bridge extension for zigbee2mqtt.
//
// Installed by bin/update-config.php as data/external_extensions/zigbee2mqttng.mjs
// and configured through data/zigbee2mqttng.json. It
//  - keeps the device list (bridge/devices, bridge/groups, bridge/info) as files
//    for the web frontend (Loxone templates, radio channel check),
//  - keeps the subscriptions of the LoxBerry MQTT gateway to the state topics of
//    the devices, so the large bridge/* messages never reach the Miniserver,
//  - optionally publishes doors and locks under the house convention shared
//    with Matter2Lox:
//        haus/tuer/<name>/offen       1 open, 0 closed      retained
//        haus/tuer/<name>/verriegelt  1 locked, 0 not       retained
//
// Do not edit the copy in data/external_extensions - it is overwritten.

import fs from "node:fs";
import path from "node:path";

const HAUS_BASE = "haus";
const HAUS_ROOT = "tuer";
const UMLAUTS = [["ä", "ae"], ["ö", "oe"], ["ü", "ue"], ["ß", "ss"], ["Ä", "ae"], ["Ö", "oe"], ["Ü", "ue"]];

function readJson(file, fallback) {
    try {
        return JSON.parse(fs.readFileSync(file, "utf8"));
    } catch {
        return fallback;
    }
}

function writeIfChanged(file, content) {
    try {
        if (fs.existsSync(file) && fs.readFileSync(file, "utf8") === content) {
            return false;
        }
        fs.writeFileSync(file, content);
        return true;
    } catch {
        return false;
    }
}

// Same rule as haus_name() in Matter2Lox: lower case, umlauts spelled out,
// everything except a-z 0-9 _ - becomes "_", at most 40 characters.
export function hausName(text) {
    let t = String(text ?? "");
    for (const [a, b] of UMLAUTS) {
        t = t.split(a).join(b);
    }
    t = t.toLowerCase().replace(/[^a-z0-9_-]+/g, "_").replace(/^_+|_+$/g, "");
    return t.slice(0, 40).replace(/_+$/, "");
}

function topicOk(name) {
    return typeof name === "string" && name !== "" && !/[+#]/.test(name);
}

// Must give the same result as zng_subscription_lines() in bin/zigbee2mqttng.php
export function subscriptionLines(base, devices, groups, availability) {
    const lines = [`${base}/bridge/state`];
    for (const device of devices ?? []) {
        if (device?.type === "Coordinator" || !topicOk(device?.friendly_name)) {
            continue;
        }
        lines.push(`${base}/${device.friendly_name}`);
        if (availability) {
            lines.push(`${base}/${device.friendly_name}/availability`);
        }
    }
    for (const group of groups ?? []) {
        if (topicOk(group?.friendly_name)) {
            lines.push(`${base}/${group.friendly_name}`);
        }
    }
    return `${lines.join("\n")}\n`;
}

// Values for the house topics from a device state, or {} if the device has
// neither a contact nor a lock. contact true means "closed" in zigbee2mqtt.
export function hausValues(state) {
    const values = {};
    if (state && typeof state.contact === "boolean") {
        values.offen = state.contact ? 0 : 1;
    }
    if (state && typeof state.lock_state === "string") {
        values.verriegelt = state.lock_state === "locked" ? 1 : 0;
    }
    return values;
}

export default class Zigbee2MqttNGExtension {
    constructor(zigbee, mqtt, state, publishEntityState, eventBus, enableDisableExtension, restartCallback, addExtension, settings, logger) {
        this.zigbee = zigbee;
        this.mqtt = mqtt;
        this.state = state;
        this.eventBus = eventBus;
        this.settings = settings;
        this.logger = logger;
        this.hausNames = new Map();
        this.devices = null;
        this.groups = null;
    }

    async start() {
        const dataDir = process.env.ZIGBEE2MQTT_DATA || path.join(process.cwd(), "data");
        this.cfg = readJson(path.join(dataDir, "zigbee2mqttng.json"), {});
        this.base = this.settings.get().mqtt.base_topic;

        this.eventBus.onMQTTMessagePublished(this, (data) => this.onPublished(data));
        this.eventBus.onStateChange(this, (data) => this.onStateChange(data));

        // The retained bridge messages were published before this extension
        // was loaded - pick them up from the cache of the MQTT controller.
        for (const topic of ["bridge/info", "bridge/devices", "bridge/groups"]) {
            const retained = this.mqtt.retainedMessages?.[`${this.base}/${topic}`];
            if (retained) {
                this.onPublished({topic: `${this.base}/${topic}`, payload: retained.payload});
            }
        }

        if (this.cfg.hausTopics) {
            this.buildHausNames();
            await this.publishAllHaus();
        } else {
            await this.clearHaus();
        }
    }

    async stop() {
        this.eventBus.removeListeners(this);
    }

    adjustMessageBeforePublish() {}

    onPublished(data) {
        const topic = data?.topic;
        if (!topic || !topic.startsWith(`${this.base}/bridge/`)) {
            return;
        }
        const part = topic.substring(this.base.length + 1);
        if (part !== "bridge/devices" && part !== "bridge/groups" && part !== "bridge/info") {
            return;
        }
        let payload;
        try {
            payload = JSON.parse(data.payload);
        } catch {
            return;
        }
        if (part === "bridge/info") {
            // only what the frontend needs - the full info carries the network key
            const info = {version: payload?.version, network: payload?.network, coordinator: payload?.coordinator};
            if (this.cfg.infoFile) {
                writeIfChanged(this.cfg.infoFile, JSON.stringify(info, null, 1));
            }
            return;
        }
        if (part === "bridge/devices") {
            this.devices = payload;
            if (this.cfg.devicesFile) {
                writeIfChanged(this.cfg.devicesFile, JSON.stringify(payload));
            }
            if (this.cfg.hausTopics) {
                this.buildHausNames();
                this.clearStaleHaus().then(() => this.publishAllHaus()).catch(() => {});
            }
        } else {
            this.groups = payload;
            if (this.cfg.groupsFile) {
                writeIfChanged(this.cfg.groupsFile, JSON.stringify(payload));
            }
        }
        this.updateSubscriptions();
    }

    updateSubscriptions() {
        if (!this.cfg.registerTopics || this.cfg.forwardMode !== "devices" || !this.cfg.subscriptionFile || this.devices === null) {
            return;
        }
        const groups = this.groups ?? readJson(this.cfg.groupsFile, []);
        if (writeIfChanged(this.cfg.subscriptionFile, subscriptionLines(this.base, this.devices, groups, this.cfg.availability))) {
            this.logger.info("Zigbee2MqttNG: MQTT gateway subscriptions updated");
        }
    }

    // <name> per device, unique: a second device with the same name gets the
    // last four digits of its IEEE address appended.
    buildHausNames() {
        this.hausNames.clear();
        const used = new Set();
        for (const device of this.zigbee.devicesIterator((d) => d.type !== "Coordinator")) {
            let name = hausName(device.name) || hausName(device.ieeeAddr);
            if (used.has(name)) {
                name = `${name}_${device.ieeeAddr.slice(-4)}`;
            }
            used.add(name);
            this.hausNames.set(device.ieeeAddr, name);
        }
    }

    async onStateChange(data) {
        if (!this.cfg.hausTopics || !data?.entity?.isDevice?.()) {
            return;
        }
        if (!("contact" in (data.update ?? {})) && !("lock_state" in (data.update ?? {}))) {
            return;
        }
        await this.publishHaus(data.entity, data.to);
    }

    async publishAllHaus() {
        for (const device of this.zigbee.devicesIterator((d) => d.type !== "Coordinator")) {
            await this.publishHaus(device, this.state.get(device));
        }
    }

    async publishHaus(device, state) {
        const values = hausValues(state);
        if (Object.keys(values).length === 0) {
            return;
        }
        if (!this.hausNames.has(device.ieeeAddr)) {
            this.buildHausNames();
        }
        const name = this.hausNames.get(device.ieeeAddr);
        if (!name) {
            return;
        }
        const remembered = readJson(this.cfg.hausFile, {});
        let changed = false;
        for (const [key, value] of Object.entries(values)) {
            const topic = `${HAUS_ROOT}/${name}/${key}`;
            await this.mqtt.publish(topic, String(value), {baseTopic: HAUS_BASE, clientOptions: {retain: true, qos: 1}});
            if (!remembered[`${HAUS_BASE}/${topic}`]) {
                remembered[`${HAUS_BASE}/${topic}`] = device.ieeeAddr;
                changed = true;
            }
        }
        if (changed && this.cfg.hausFile) {
            writeIfChanged(this.cfg.hausFile, JSON.stringify(remembered, null, 1));
        }
    }

    // House topics switched off: remove every retained topic sent before
    async clearHaus() {
        await this.removeHaus(() => true);
    }

    // Device renamed or removed: remove the topics under its old name
    async clearStaleHaus() {
        await this.removeHaus((topic, ieee) => {
            const name = this.hausNames.get(ieee);
            return !name || !topic.startsWith(`${HAUS_BASE}/${HAUS_ROOT}/${name}/`);
        });
    }

    async removeHaus(shouldRemove) {
        if (!this.cfg.hausFile) {
            return;
        }
        const remembered = readJson(this.cfg.hausFile, {});
        const removed = [];
        for (const [full, ieee] of Object.entries(remembered)) {
            if (!shouldRemove(full, ieee)) {
                continue;
            }
            if (full.startsWith(`${HAUS_BASE}/${HAUS_ROOT}/`)) {
                await this.mqtt.publish(full.substring(HAUS_BASE.length + 1), "", {baseTopic: HAUS_BASE, clientOptions: {retain: true, qos: 1}});
            }
            delete remembered[full];
            removed.push(full);
        }
        if (removed.length > 0) {
            writeIfChanged(this.cfg.hausFile, JSON.stringify(remembered, null, 1));
            this.logger.info(`Zigbee2MqttNG: removed ${removed.length} topic(s) below ${HAUS_BASE}/${HAUS_ROOT}`);
        }
    }
}
