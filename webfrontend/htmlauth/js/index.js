
/**
 * Fetches the form data from the backend
 * @param {string} name Name of the form
 */
function fetchFormData(name) {
    return new Promise((resolve, reject) => {
        const jqxhr = $.getJSON(`ajax.php/?action=getFormData&form=${name}`);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

/**
 * updates the form data on the backend
 * @param {string} name Name of the form
 */
function updateFormData(name) {

    data = $(`#${name}`).serializeArray();
    /* Because serializeArray() ignores unset checkboxes and radio buttons: */
    const uncheckedItems = $(`#${name} input[type=checkbox]:not(:checked)`).map(
        function () {

            return {
                "name": this.name,
                "value": false
            }
        }).get();
    data = data.concat(uncheckedItems);

    return new Promise((resolve, reject) => {
        const jqxhr = $.post(`ajax.php/?action=setFormData&form=${name}`, data);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

function applyChanges() {
    return new Promise((resolve, reject) => {
        const jqxhr = $.post(`ajax.php/?action=applyChanges`);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

/**
 * Fetches the pid of the zigbee2mqtt service
 */
function getPid() {
    return new Promise((resolve, reject) => {
        const jqxhr = $.getJSON(`ajax.php/?action=getPid`);
        jqxhr.done(function (data) {
            if (data.pid != 0) {
                $("#servicepid").html(data.pid);
                $("#service_not_running").fadeOut();
                $("#service_running").fadeIn();
            }
            else {
                $("#service_not_running").fadeIn();
                $("#service_running").fadeOut();
            }

        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            $("#service_not_running").fadeIn();
            $("#service_running").fadeOut();
        });
    });
}

/**
 * Sets the form data
 * @param {string} name of the form
 * @param {object} data for the form values
 */
function setFormData(name, data) {
    Object.keys(data).forEach((key) => {
        try {
            let field = $(`#${name}\\[${key}\\]`);
            if (field !== 'undefined') {
                if (field.is("select")) {
                    field.val(data[key] === null ? "" : String(data[key]));
                    try { field.selectmenu('refresh'); } catch (e) { }
                }
                else if (field.attr("type") !== "checkbox") {
                    field.val(data[key]);
                }
                else {
                    field.prop('checked', data[key]).checkboxradio('refresh');
                }
            }
        }
        catch (e) {
        }

    });
}

function saveAndApply() {

    $(".saveok").fadeOut();
    $(".saveerror").fadeOut();
    $(".submitting").fadeIn();

    const servicePromise = updateFormData("ServiceConfig");
    const mqttPromise = updateFormData("MqttConfig");

    Promise.all([servicePromise, mqttPromise]).then(function (values) {
        applyChanges().then(function (values) {
            $(".submitting").fadeOut();
            $(".saveok").fadeIn();
            getPid();
            location.reload();
        })
    })
        .catch(function (values) {
            $(".submitting").fadeOut();
            $(".saveerror").fadeIn();
        });


}

function viewhide() {
    if ($("#MqttConfig\\[usemqttgateway\\]").is(":checked")) {
        $(".ownbroker").fadeOut();
    } else {
        $(".ownbroker").fadeIn();
    }

}

let serialPorts = [];

function escapeHtml(text) {
    return $("<div>").text(text).html();
}

/**
 * Lists the serial devices and warns about names like /dev/ttyACM0 that may
 * change on every boot as soon as a second stick (e.g. Thread) is plugged in
 */
function loadSerialPorts() {
    $.getJSON(`ajax.php/?action=getSerialPorts`).done(function (ports) {
        serialPorts = ports;
        const list = $("#serialports").empty();
        const lines = [];
        ports.forEach(p => {
            list.append($("<option>").attr("value", p.path));
            if (p.stable) {
                lines.push(`<code>${escapeHtml(p.path)}</code> &rarr; ${escapeHtml(p.target)}`);
            }
        });
        $("#portlist").html(lines.length ? `${$("#portlist").data("title") || ""}<br>${lines.join("<br>")}` : "");
        checkPort();
    });
}

function checkPort() {
    const port = ($("#ServiceConfig\\[port\\]").val() || "").trim();
    const warning = $("#portwarning");
    if (!/^\/dev\/tty(ACM|USB)[0-9]+$/.test(port)) {
        warning.hide();
        return;
    }
    const stable = serialPorts.find(p => p.stable && p.target === port);
    let text = warning.data("text");
    if (stable) {
        text += ` <a href="#" id="usestableport">${escapeHtml(stable.path)}</a>`;
    }
    warning.html(text).show();
    $("#usestableport").click(function (e) {
        e.preventDefault();
        $("#ServiceConfig\\[port\\]").val(stable.path);
        checkPort();
    });
}

/**
 * Shows the Zigbee and the Thread channel next to each other
 */
function loadRadioInfo() {
    $.getJSON(`ajax.php/?action=getRadioInfo`).done(function (info) {
        const box = $("#radioinfo");
        let text = box.data("zigbee").replace("%s", info.zigbee);
        if (info.thread) {
            text += "<br>" + box.data("thread").replace("%s", info.thread).replace("%t", escapeHtml(info.threadSource));
            if (info.level === "conflict") {
                text = `<div class="z2l-error">${text}<br>${box.data("conflict")}</div>`;
            } else if (info.level === "adjacent") {
                text = `<div class="z2l-warning">${text}<br>${box.data("adjacent")}</div>`;
            } else {
                text += `<br><span class="z2l-ok">${box.data("ok")}</span>`;
            }
        }
        box.html(text);
    });
}

/**
 * Document ready function
 */
$(document).ready(function () {

    $("#saveapply").click(function () {
        saveAndApply();
    });
    $("#MqttConfig\\[usemqttgateway\\]").click(function () {
        viewhide();
    });

    fetchFormData("ServiceConfig")
        .then(data => {
            setFormData("ServiceConfig", data);
            checkPort();
        });

    fetchFormData("MqttConfig")
        .then(data => {
            setFormData("MqttConfig", data);
            viewhide();
        });

    $("#ServiceConfig\\[port\\]").on("input change", checkPort);
    loadSerialPorts();
    loadRadioInfo();

    getPid();
    setInterval(function () { getPid(); }, 5000);

})

