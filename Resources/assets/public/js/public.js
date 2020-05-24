document.addEventListener("DOMContentLoaded", function(event) {
    Litepicker.prototype.showTooltip = function(element, text) {
        $("#booking-form").hide();
    }, $.post(cb_ajax.ajax_url, {
        _ajax_nonce: cb_ajax.nonce,
        action: "calendar_data"
    }, function(data) {
        new Litepicker({
            minDate: data.startDate,
            maxDate: data.endDate,
            element: document.getElementById("litepicker"),
            inlineMode: !0,
            firstDay: 1,
            lang: "de-DE",
            numberOfMonths: 2,
            numberOfColumns: 2,
            singleMode: !1,
            showWeekNumbers: !1,
            autoApply: !0,
            lockDays: data.lockDays,
            bookedDays: data.bookedDays,
            highlightedDays: data.highlightedDays,
            disallowBookedDaysInRange: !0,
            disallowLockDaysInRange: !0,
            maxDays: 3,
            buttonText: {
                apply: "Buchen",
                cancel: "Abbrechen"
            },
            onSelect: function(date1, date2) {
                $("#booking-form").show(), day1 = data.days[moment(date1).format("YYYY-MM-DD")], 
                day2 = data.days[moment(date2).format("YYYY-MM-DD")], $("#booking-form select[name=start-date]").empty(), 
                $("#booking-form select[name=end-date]").empty(), $("#booking-form #start-date").text(moment(date1).format("DD.MM.YYYY")), 
                $("#booking-form #end-date").text(moment(date2).format("DD.MM.YYYY")), $.each(day1.slots, function(index, slot) {
                    $("#booking-form select[name=start-date]").append(new Option(slot.timestart + " - " + slot.timeend, slot.timestampstart));
                }), $.each(day2.slots, function(index, slot) {
                    $("#booking-form select[name=end-date]").append(new Option(slot.timestart + " - " + slot.timeend, slot.timestampend));
                });
            }
        });
    });
});