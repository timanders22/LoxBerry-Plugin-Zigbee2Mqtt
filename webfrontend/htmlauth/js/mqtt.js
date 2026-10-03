/**
 * MQTT tab
 */

function viewhide() {
    const gateway = $("#MqttConfig\\[usemqttgateway\\]");
    const useGateway = gateway.length > 0 && gateway.is(":checked");
    $(".ownbroker").toggle(!useGateway);
    $(".gatewayonly").toggle(useGateway);
}

$(document).ready(function () {
    $("#saveapply").click(function () {
        saveAndApply(["MqttConfig"]);
    });
    $("#MqttConfig\\[usemqttgateway\\]").change(viewhide);

    fetchFormData("MqttConfig").then(data => {
        setFormData("MqttConfig", data);
        const password = $("#MqttConfig\\[password\\]");
        password.attr("placeholder", data.passwordSet ? password.data("set") : "");
        viewhide();
    });

    getPid();
    setInterval(getPid, 5000);
});
