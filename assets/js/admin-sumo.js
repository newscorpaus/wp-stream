/* globals ajaxurl, wp_stream */
jQuery( function( $ ) {

	var $window = $( window );
	var $document = $( document );
	var $sumo_spinner, $sumo_status, $sumo_count;
	var $results_list = $('#the-list');
	var $empty_results = $('tr.no-items');
	var $empty_stream_list = $('.stream-list-table-no-items').find('p');

	wp_stream.sumo_loaded_results = 0;
	wp_stream.sumo_results_count = 0;
	wp_stream.sumo_error = false;

	$document.ready( function() {
		$empty_stream_list.text('Loading...');

		$('.tablenav.bottom > br').before( '<div class="sumo_status alignright"><span class="sumo-spinner spinner"></span><span class="sumo-status">Loading</span><span class="sumo-count"></span></div>' );
		$sumo_spinner = $( '.sumo-spinner' );
		$sumo_status = $('.sumo-status');
		$sumo_count = $('.sumo-count');
		check_job_status();
	});

	$window.scroll( function() {
		if( $window.scrollTop() + $window.height() > $document.height() - 200 ) {
			wp_stream.hit_bottom = true;
			check_load_more_data();
		}
	});

	function check_job_status() {
		if ( ! wp_stream.sumo_job ) {
			return false;
		}

		$sumo_spinner.css( { visibility: 'visible' } ).show();

		$.ajax({
			type: 'POST',
			url: ajaxurl,
			data: {
				action: 'stream_sumo_job_check',
				job: wp_stream.sumo_job,
				nonce: wp_stream.sumo_nonce,
			},
			dataType: 'json',
			success: function( response ) {
				if( ! response.success ) {
					if ( response.data ) {
						alert(response.data);
					}
					$sumo_spinner.attr('hidden', true).hide();
					$empty_stream_list.text( 'Error occurred. Try to refresh the page.' );
					$sumo_status.text( 'Error occurred' );
					wp_stream.sumo_error = true;
				} else if ( response.data && response.data.status ) {
					$sumo_status.text( response.data.status );
					$sumo_count.text( ' | Found: ' + response.data.count );

					if ( response.data.status == 'DONE GATHERING RESULTS' || response.data.status == 'CANCELLED' ) {
						$sumo_spinner.attr('hidden', true).hide();
						if ( ! response.data.count ) {
							$empty_stream_list.text( 'Nothing found' );
						}
					} else {
						setTimeout( check_job_status, 1000 );
					}

					wp_stream.sumo_results_count = response.data.count;
					check_load_more_data();
				}
			}
		});
	}

	function check_load_more_data() {
		if ( ! wp_stream.sumo_results_count ||
			wp_stream.sumo_error ||
			( wp_stream.sumo_loaded_results && wp_stream.sumo_results_count <= wp_stream.sumo_loaded_results ) //all data loaded
		) {
			return false;
		}

		if ( ! wp_stream.sumo_loaded_results ||  //first data available
			document.body.scrollHeight < document.body.clientHeight || //no scroll yet
			wp_stream.hit_bottom  //indefinite scrolling
		) {
			wp_stream.hit_bottom = false;
			load_results_page();
		}
	}

	function load_results_page() {
		if ( wp_stream.sumo_results_loading ) {
			return false;
		}

		wp_stream.sumo_results_loading = true;
		$sumo_spinner.show();

		$.ajax({
			type: 'POST',
			url: ajaxurl,
			data: {
				action: 'stream_sumo_load_results',
				job: wp_stream.sumo_job,
				offset: wp_stream.sumo_loaded_results,
				nonce: wp_stream.sumo_nonce,
			},
			dataType: 'json',
			complete: function() {
				wp_stream.sumo_results_loading = false;
			},
			success: function( response ) {
				if ( ! response.success ) {
					if (response.data ) {
						alert(response.data);
					}
					$sumo_spinner.attr('hidden', true).hide();
					$empty_stream_list.text( 'Error occurred. Try to refresh the page.' );
					$sumo_status.text( 'Error occurred' );
					wp_stream.sumo_error = true;
				} else if ( response && response.data ) {
					$empty_results.remove();
					$results_list.append( response.data.list );
					wp_stream.sumo_loaded_results += response.data.count;
					wp_stream.sumo_results_loading = false;
					check_load_more_data();
					if( $sumo_spinner.attr('hidden') ) {
						$sumo_spinner.hide(); //do not hide if data is still gathering
					}
				}
			}
		});
	}
});

