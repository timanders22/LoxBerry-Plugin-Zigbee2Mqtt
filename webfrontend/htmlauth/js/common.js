/**
 * Form helpers shared by the settings and the MQTT tab.
 * All requests carry "X-Requested-With: XMLHttpRequest" (jQuery does that for
 * same-origin requests) - ajax.php only accepts changing actions with it.
 */

/**
 * Fetches the form data from the backend
 * @param {string} name Name of the form
 */
function fetchFormData(name) {
    return new Promise((resolve, reject) => {
        $.getJSON(`ajax.php?action=getFormData&form=${name}`)
            .done(resolve)
            .fail((jqxhr, textStatus, error) => reject(error));
    });
}

/**
 * Sets the form data
 * @param {string} name of the form
 * @param {object} data for the form values
 */
function setFormData(name, data) {
    Object.keys(data).forEach((key) => {
        const field = $(`#${name}\\[${key}\\]`);
        if (field.length === 0) {
            return;
        }
        try {
            if (field.is("select")) {
                field.val(data[key] === null ? "" : String(data[key]));
                try { field.selectmenu("refresh"); } catch (e) { }
            } else if (field.attr("type") === "checkbox") {
                field.prop("checked", data[key] === true || data[key] === "true" || data[key] === 1 || data[key] === "1");
                try { field.checkboxradio("refresh"); } catch (e) { }
            } else {
                field.val(data[key]);
            }
        } catch (e) {
        }
    });
}

/**
 * Sends the form to the backend. Resolves with the answer; a validation
 * error of the backend rejects with {errors: [...]}.
 * @param {string} name Name of the form
 */
function updateFormData(name) {
    let data = $(`#${name}`).serializeArray();
    /* Because serializeArray() ignores unset checkboxes: */
    const uncheckedItems = $(`#${name} input[type=checkbox]:not(:checked)`).map(function () {
        return { "name": this.name, "value": false };
    }).get();
    data = data.concat(uncheckedItems);

    return new Promise((resolve, reject) => {
        $.post(`ajax.php?action=setFormData&form=${name}`, data, null, "json")
            .done(function (answer) {
                if (answer && answer.result) {
                    resolve(answer);
                } else {
                    reject(answer || {});
                }
            })
            .fail(function (jqxhr) {
                reject(jqxhr.responseJSON || {});
            });
    });
}

function applyChanges() {
    return new Promise((resolve, reject) => {
        $.post(`ajax.php?action=applyChanges`)
            .done(resolve)
            .fail((jqxhr, textStatus, error) => reject(error));
    });
}

/**
 * Shows the validation errors of the backend and marks the fields
 * @param {object} answer {errors: [{field, message}]}
 */
function showErrors(answer) {
    const box = $("#validationerrors");
    $(".zng-field-error").removeClass("zng-field-error");
    const errors = (answer && answer.errors) || [];
    if (errors.length === 0) {
        box.hide();
        return;
    }
    const list = $("<ul>");
    errors.forEach(function (e) {
        list.append($("<li>").text(e.message));
        if (e.field) {
            $(`[name="${e.form}[${e.field}]"]`).addClass("zng-field-error");
        }
    });
    box.empty().append($("<b>").text(box.data("title"))).append(list).show();
}

/**
 * Saves the given forms and restarts zigbee2mqtt
 * @param {string[]} forms names of the forms
 */
function saveAndApply(forms) {
    $(".saveok").hide();
    $(".saveerror").hide();
    $(".submitting").show();
    showErrors(null);

    Promise.all(forms.map(updateFormData))
        .then(function () {
            return applyChanges();
        })
        .then(function () {
            $(".submitting").hide();
            $(".saveok").show();
            location.reload();
        })
        .catch(function (answer) {
            $(".submitting").hide();
            $(".saveerror").show();
            showErrors(answer);
        });
}

/**
 * Shows whether the service runs
 */
function getPid() {
    $.getJSON(`ajax.php?action=getPid`)
        .done(function (data) {
            if (data.pid != 0) {
                $("#servicepid").text(data.pid);
                $("#service_not_running").hide();
                $("#service_running").show();
            } else {
                $("#service_not_running").show();
                $("#service_running").hide();
            }
        })
        .fail(function () {
            $("#service_not_running").show();
            $("#service_running").hide();
        });
}

function escapeHtml(text) {
    return $("<div>").text(text).html();
}
