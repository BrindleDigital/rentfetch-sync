/**
 * Blaze Slider Initialization for Rentfetch Form Availability
 *
 * This file handles the initialization of Blaze sliders for availability date containers.
 * It listens for custom events triggered when availability content is updated.
 */

jQuery(document).ready(function ($) {
	/**
	 * Calculate the number of slides to show based on form width
	 * @returns {number} Number of slides to show
	 */
	function calculateSlidesToShow() {
		const $form = $('#rentfetch-form');
		const formWidth = $form.width();

		console.log('Form width:', formWidth + 'px');

		if (formWidth >= 2000) {
			return 11;
		} else if (formWidth >= 1800) {
			return 10;
		} else if (formWidth >= 1600) {
			return 9;
		} else if (formWidth >= 1400) {
			return 8;
		} else if (formWidth >= 1000) {
			return 7;
		} else if (formWidth >= 870) {
			return 6;
		} else if (formWidth >= 740) {
			return 5;
		} else if (formWidth >= 610) {
			return 4;
		} else if (formWidth >= 480) {
			return 3;
		} else {
			return 2;
		}
	}

	/**
	 * Initialize Blaze slider for availability dates
	 * @param {jQuery} $container - The container element that holds the blaze slider
	 */
	function initializeAvailabilitySlider($container) {
		const $blazeSlider = $container.find('.blaze-slider');

		if ($blazeSlider.length === 0) {
			console.log('No blaze slider found in container');
			return;
		}

		// Check if BlazeSlider is available
		if (typeof BlazeSlider === 'undefined') {
			console.error('BlazeSlider is not loaded');
			return;
		}

		// Add a small delay to ensure DOM is fully rendered
		setTimeout(function () {
			console.log(
				'Initializing Blaze slider with element:',
				$blazeSlider[0]
			);
			console.log(
				'Slider has children:',
				$blazeSlider.find('.blaze-track .availability-date').length
			);

			// Calculate slides to show based on form width
			const slidesToShow = calculateSlidesToShow();
			console.log('Calculated slides to show:', slidesToShow);

			try {
				const slider = new BlazeSlider($blazeSlider[0], {
					all: {
						slidesToShow: slidesToShow,
						slideGap: '10px',
						loop: false,
						enableAutoplay: false,
						transitionDuration: 300,
						slidesToScroll: slidesToShow,
					},
				});
			} catch (error) {
				console.error('Error initializing Blaze slider:', error);
			}
		}, 100);
	}

	/**
	 * Listen for custom event when availability content is updated
	 */
	$(document).on(
		'rentfetch:availability-content-updated',
		function (event, $container) {
			initializeAvailabilitySlider($container);
		}
	);

	/**
	 * Handle window resize to recalculate slider configuration
	 */
	let resizeTimeout;
	$(window).on('resize', function () {
		clearTimeout(resizeTimeout);
		resizeTimeout = setTimeout(function () {
			const $container = $('.rentfetch-availability-dates');
			if ($container.find('.blaze-slider').length > 0) {
				// Destroy existing slider if it exists
				const $blazeSlider = $container.find('.blaze-slider');
				if ($blazeSlider[0].blazeSlider) {
					$blazeSlider[0].blazeSlider.destroy();
				}

				// Reinitialize with new dimensions
				initializeAvailabilitySlider($container);
			}
		}, 250); // Debounce resize events
	});

	/**
	 * Alternative initialization method for direct calls
	 * This can be used if you need to manually trigger slider initialization
	 */
	window.rentfetchInitializeAvailabilitySlider = function ($container) {
		initializeAvailabilitySlider($container);
	};
});
