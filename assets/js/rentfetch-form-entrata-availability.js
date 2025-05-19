jQuery(document).ready(function ($) {
	const $propertySelect = $('#rentfetch-form-property');
	const $availabilityDatesContainer = $('.rentfetch-availability-dates');
	const $availabilityTimesContainer = $('.rentfetch-availability-times');
	const $scheduleField = $('#rentfetch-form-schedule');
	const $appointmentDateField = $('#rentfetch-form-appointment_date');
	const $appointmentStartTimeField = $(
		'#rentfetch-form-appointment_start_time'
	);
	const $appointmentEndTimeField = $('#rentfetch-form-appointment_end_time');
	const $submitButton = $('.rentfetch-form-button');

	const rentfetchEntrataTourAvailabilityAjax =
		window.rentfetchEntrataTourAvailabilityAjax;

	function toggleSubmitButton() {
		if ($availabilityTimesContainer.find('li.selected').length) {
			$submitButton.show();
		} else {
			$submitButton.hide();
		}
	}

	// Initially hide the button
	$submitButton.hide();

	function showAvailabilityContainers() {
		$availabilityDatesContainer
			.removeClass('rentfetch-hidden')
			.addClass('rentfetch-visible');
		$availabilityTimesContainer
			.removeClass('rentfetch-hidden')
			.addClass('rentfetch-visible');
	}

	function hideAvailabilityContainers() {
		$availabilityDatesContainer
			.removeClass('rentfetch-visible')
			.addClass('rentfetch-hidden');
		$availabilityTimesContainer
			.removeClass('rentfetch-visible')
			.addClass('rentfetch-hidden');
	}

	hideAvailabilityContainers();

	function updateScheduleFields() {
		const selectedDate = $availabilityDatesContainer
			.find('li.selected')
			.data('date');
		const selectedTime = $availabilityTimesContainer
			.find('li.selected')
			.text();

		if (selectedDate) {
			const date = new Date(selectedDate);
			const formattedDate = `${(date.getMonth() + 1)
				.toString()
				.padStart(2, '0')}/${date
				.getDate()
				.toString()
				.padStart(2, '0')}/${date.getFullYear()}`;
			$appointmentDateField.val(formattedDate);
		}

		if (selectedTime) {
			console.log('Selected time:', selectedTime);
			// we need to get both the start and end time, then insert them into the appropriate fields
			const timeParts = selectedTime.split(' to ');
			if (timeParts.length === 2) {
				let startTime = timeParts[0].trim();
				let endTime = timeParts[1].trim();
				const period = endTime.split(' ')[1]; // Extract am/pm

				// Determine the correct period for start time
				if (!startTime.includes('am') && !startTime.includes('pm')) {
					let [startHour] = startTime.split(':');
					startHour = parseInt(startHour);
					let endHour = parseInt(endTime.split(' ')[0].split(':')[0]);

					// Special handling for noon hours
					if (startHour === 12) {
						// 12:xx is always PM
						startTime += ' pm';
					} else if (period.toLowerCase() === 'pm') {
						// For PM end times
						if (
							startHour < 12 &&
							startHour > endHour &&
							endHour !== 12
						) {
							// Only if start hour crosses noon boundary (like 11am to 1pm)
							startTime += ' am';
						} else {
							// Otherwise assume the same period as end time
							startTime += ' pm';
						}
					} else {
						// For AM end times, use AM for start time
						startTime += ' ' + period;
					}
				}

				// Convert to 24-hour format
				const formatTo24Hour = (timeStr) => {
					let [time, period] = timeStr.split(' ');
					let [hours, minutes] = time.includes(':')
						? time.split(':')
						: [time, '00'];

					hours = parseInt(hours);

					// Convert to 24-hour format
					if (period && period.toLowerCase() === 'pm' && hours < 12) {
						hours += 12;
					} else if (
						period &&
						period.toLowerCase() === 'am' &&
						hours === 12
					) {
						hours = 0;
					}

					// Format as HH:MM:SS
					return `${hours
						.toString()
						.padStart(2, '0')}:${minutes.padStart(2, '0')}:00`;
				};

				const formattedStartTime = formatTo24Hour(startTime);
				const formattedEndTime = formatTo24Hour(endTime);

				$appointmentStartTimeField.val(formattedStartTime);
				$appointmentEndTimeField.val(formattedEndTime);
			}
		}
	}

	function triggerEntrataAvailabilityFetch(propertyId) {
		if (
			!rentfetchEntrataTourAvailabilityAjax.ajaxurl ||
			!rentfetchEntrataTourAvailabilityAjax.nonce
		) {
			console.error('WordPress AJAX URL or nonce is not available.');
			return;
		}

		$availabilityDatesContainer.empty();
		$availabilityTimesContainer.empty();
		hideAvailabilityContainers();

		$.ajax({
			url: rentfetchEntrataTourAvailabilityAjax.ajaxurl,
			type: 'POST',
			data: {
				action: 'rentfetch_fetch_entrata_availability',
				nonce: rentfetchEntrataTourAvailabilityAjax.nonce,
				property_id: propertyId,
			},
			success: function (response) {
				if (response.success && response.data.response) {
					const availableTours =
						response.data.response.result.availableTours
							.availableTour;
					if (availableTours && availableTours.length > 0) {
						displayAvailability(availableTours);
						showAvailabilityContainers();
					} else {
						$availabilityDatesContainer.html(
							'<p>No availability found.</p>'
						);
						showAvailabilityContainers();
					}
				} else {
					console.error('AJAX Error:', response.data.message);
					$availabilityDatesContainer.html(
						'<p>Error fetching availability.</p>'
					);
					showAvailabilityContainers();
				}
			},
			error: function (jqXHR, textStatus, errorThrown) {
				console.error('AJAX Request Failed:', textStatus, errorThrown);
				$availabilityDatesContainer.html(
					'<p>Error fetching availability.</p>'
				);
				showAvailabilityContainers();
			},
		});
	}

	function displayAvailability(availableTours) {
		let dateHtml = '<ul>';
		let timeHtml = '';

		console.log(availableTours);

		availableTours.forEach((tour) => {
			const date = new Date(tour.tourDate);
			const options = { weekday: 'long', month: 'long', day: 'numeric' };
			const formattedDate = date.toLocaleDateString('en-US', options);

			const [weekday, monthDay] = formattedDate.split(', ');
			const [month, day] = monthDay.split(' ');

			dateHtml += `<li data-date="${tour.tourDate}">
				<span class="rentfetch-weekday">${weekday}</span>
				<span class="rentfetch-date">
					<span class="rentfetch-month">${month}</span> 
					<span class="rentfetch-day">${day}</span>
				</span>
			</li>`;
		});

		dateHtml += '</ul>';
		$availabilityDatesContainer.html(dateHtml);
		$availabilityTimesContainer.html(timeHtml);
		$availabilityTimesContainer
			.removeClass('rentfetch-visible')
			.addClass('rentfetch-hidden');

		$availabilityDatesContainer.find('li').on('click', function () {
			$availabilityDatesContainer.find('li').removeClass('selected');
			$(this).addClass('selected');

			updateScheduleFields();

			// clear the time selection
			$appointmentStartTimeField.val('');
			$appointmentEndTimeField.val('');

			// Clear the schedule field and hide button when a new date is selected
			$scheduleField.val('');
			$submitButton.hide();

			const selectedDate = $(this).data('date');
			const selectedTour = availableTours.find(
				(tour) => tour.tourDate === selectedDate
			);

			$availabilityTimesContainer.empty();
			if (selectedTour && selectedTour.tourTime.length > 0) {
				let currentTimeHtml = '<ul>';
				selectedTour.tourTime.forEach((time) => {
					const timeWithoutTimezone = time.replace(
						/ [A-Z]{3,4}/g,
						''
					);
					const formattedTime = timeWithoutTimezone.replace(
						/(\d{2}):(\d{2}) - (\d{2}):(\d{2})/,
						(match, hour1, minute1, hour2, minute2) => {
							const hour1Int = parseInt(hour1, 10);
							const hour2Int = parseInt(hour2, 10);
							const period1 = hour1Int >= 12 ? 'pm' : 'am';
							const period2 = hour2Int >= 12 ? 'pm' : 'am';
							const hour12_1 = hour1Int % 12 || 12;
							const hour12_2 = hour2Int % 12 || 12;
							const min1Display =
								minute1 === '00' ? '' : ':' + minute1;
							const min2Display =
								minute2 === '00' ? '' : ':' + minute2;

							// If periods match, only show once at the end
							if (period1 === period2) {
								return `${hour12_1}${min1Display} to ${hour12_2}${min2Display} ${period2}`;
							} else {
								return `${hour12_1}${min1Display} ${period1} to ${hour12_2}${min2Display} ${period2}`;
							}
						}
					);
					currentTimeHtml += `<li>${formattedTime}</li>`;
				});
				currentTimeHtml += '</ul>';
				$availabilityTimesContainer.html(currentTimeHtml);
				$availabilityTimesContainer
					.removeClass('rentfetch-hidden')
					.addClass('rentfetch-visible');

				// Add click handler for time selection
				$availabilityTimesContainer.find('li').on('click', function () {
					$availabilityTimesContainer
						.find('li')
						.removeClass('selected');
					$(this).addClass('selected');
					updateScheduleFields();
					toggleSubmitButton();
				});
			} else {
				$availabilityTimesContainer.html(
					'<p>No times available for this date.</p>'
				);
				$availabilityTimesContainer
					.removeClass('rentfetch-hidden')
					.addClass('rentfetch-visible');
			}
		});
	}

	function checkAndFetchAvailability() {
		const $selectedOption = $propertySelect.find('option:selected');
		const selectedPropertyId = $selectedOption.val();
		const propertySource = $selectedOption.data('property-source');

		// Hide button when changing properties
		$submitButton.hide();

		if (selectedPropertyId && propertySource) {
			if (propertySource === 'entrata') {
				triggerEntrataAvailabilityFetch(selectedPropertyId);
			} else {
				console.log('Selected property is not from Entrata.');
				$availabilityDatesContainer.empty();
				$availabilityTimesContainer.empty();
				hideAvailabilityContainers();
			}
		} else {
			$availabilityDatesContainer.empty();
			$availabilityTimesContainer.empty();
			hideAvailabilityContainers();
		}
	}

	checkAndFetchAvailability();

	$propertySelect.on('change', function () {
		checkAndFetchAvailability();
	});
});
