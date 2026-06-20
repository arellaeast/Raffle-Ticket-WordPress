(function ($) {
	'use strict';

	$(document).on('click', '.raffle-ticket-button', function (e) {
		e.preventDefault();

		var $button = $(this);
		var $wrapper = $button.closest('.raffle-ticket-claim');
		var $status = $wrapper.find('.raffle-ticket-status');

		if ($button.prop('disabled')) {
			return;
		}

		$button.prop('disabled', true).text('…');

		$.ajax({
			url: RaffleTicketData.ajax_url,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'raffle_ticket_claim',
				nonce: RaffleTicketData.nonce
			}
		})
			.done(function (response) {
				if (response.success) {
					$status
						.removeClass('raffle-ticket-error')
						.text(response.data.message + ' (#' + response.data.ticket_number + ')');
					$wrapper.attr('data-claimed', '1');
					$button.text(
						$button.data('claimed-label') || $button.text()
					);
				} else {
					$button.prop('disabled', false);
					$status.addClass('raffle-ticket-error').text(response.data.message || 'Something went wrong.');
					if (response.data && response.data.ticket_number) {
						$wrapper.attr('data-claimed', '1');
					}
				}
			})
			.fail(function () {
				$button.prop('disabled', false);
				$status.addClass('raffle-ticket-error').text('Network error — please try again.');
			})
			.always(function () {
				if ($wrapper.attr('data-claimed') !== '1') {
					$button.prop('disabled', false);
				}
			});
	});
})(jQuery);
