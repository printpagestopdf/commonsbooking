<?php


namespace CommonsBooking\Model;

use CommonsBooking\Helper\Wordpress;
use DateTime;
use Exception;

use CommonsBooking\CB\CB;
use CommonsBooking\Helper\Helper;
use CommonsBooking\Settings\Settings;
use CommonsBooking\Repository\Timeframe;
use CommonsBooking\Messages\BookingMessage;
use CommonsBooking\Repository\BookingCodes;
use CommonsBooking\Service\iCalendar;
use DateTimeImmutable;
use DateInterval;

class Booking extends \CommonsBooking\Model\Timeframe {

	const START_TIMEFRAME_GRIDSIZE = 'start-timeframe-gridsize';

	const END_TIMEFRAME_GRIDSIZE = 'end-timeframe-gridsize';

	/**
	 * Booking states.
     *
	 * @var string[]
	 */
	public static $bookingStates = [
		'canceled',
		'confirmed',
		'unconfirmed',
	];

	/**
	 * Returns the booking code.
     *
	 * @return mixed
	 */
	public function getBookingCode() {
		return $this->getMeta( COMMONSBOOKING_METABOX_PREFIX . 'bookingcode' );
	}

	/**
	 * Sets post_status to canceled.
	 */
	public function cancel() {

		// check if booking has ended
		if ( $this->isPast() ) {
			return false;
		}

		// workaround, because wp_update_post deletes all meta data

		global $wpdb;
		$sql = $wpdb->prepare(
			'UPDATE ' . $wpdb->prefix . "posts SET post_status='canceled' WHERE ID = %d",
			$this->post->ID
		);
		$wpdb->query( $sql );

		add_post_meta( $this->post->ID, 'cancellation_time', current_time( 'timestamp' ) );

		$this->sendCancellationMail();
	}

	/**
	 * Send mail to booking user, that it was canceled.
	 */
	protected function sendCancellationMail() {
		$booking_msg = new BookingMessage( $this->getPost()->ID, 'canceled' );
		$booking_msg->triggerMail();
	}

	/**
	 * Returns rendered booking code for using in email-template (booking confirmation mail)
     *
	 * @return string
	 * @throws Exception
	 */
	public function formattedBookingCode(): string {
		$htmloutput = '';
		if (
			$this->getMeta( COMMONSBOOKING_METABOX_PREFIX . 'bookingcode' ) &&
			$this->post_status == 'confirmed' && (
				$this->showBookingCodes() ||
				( $this->getBookableTimeFrame() && $this->getBookableTimeFrame()->showBookingCodes() )
			)
		) {
			// translators: %s = Booking code
			$htmloutput = '<br>' . sprintf( commonsbooking_sanitizeHTML( __( 'Your booking code is: %s', 'commonsbooking' ) ), $this->getMeta( COMMONSBOOKING_METABOX_PREFIX . 'bookingcode' ) ) . '<br>';
		}

		return $htmloutput;
	}

	/**
	 * Returns true if booking codes shall be shown in frontend.
     *
	 * @return bool
	 */
	public function showBookingCodes(): bool {
		return $this->getMeta( 'show-booking-codes' ) == 'on';
	}

	/**
	 * Returns suitable bookable Timeframe for booking.
     *
	 * @return null|\CommonsBooking\Model\Timeframe
	 * @throws Exception
	 */
	public function getBookableTimeFrame(): ?\CommonsBooking\Model\Timeframe {
		$locationId = $this->getMeta( \CommonsBooking\Model\Timeframe::META_LOCATION_ID );
		$itemId     = $this->getMeta( \CommonsBooking\Model\Timeframe::META_ITEM_ID );

		$response = Timeframe::getBookable(
			[ $locationId ],
			[ $itemId ],
			date( CB::getInternalDateFormat(), intval( $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_START ) ) ),
			true
		);

		if ( count( $response ) ) {
			return array_shift( $response );
		}

		return null;
	}

	/**
	 * Assings relevant meta fields from related bookable timeframe to booking.
     *
	 * @throws Exception
	 */
	public function assignBookableTimeframeFields() {
		$timeframe = $this->getBookableTimeFrame();
		if ( $timeframe ) {
			$neededMetaFields = [
				'full-day',
				'grid',
				'start-time',
				'end-time',
				'show-booking-codes',
				'timeframe-max-days',
			];
			foreach ( $neededMetaFields as $fieldName ) {
				$fieldValue = get_post_meta(
					$timeframe->ID,
					$fieldName,
					true
				);
				if ( in_array( $fieldName, [ 'start-time', 'end-time' ] ) ) {
					$fieldValue = $this->sanitizeTimeField( $fieldName );
				}
				update_post_meta(
					$this->post->ID,
					$fieldName,
					$fieldValue
				);
			}

			// If there exists a booking code, add it.
			$bookingCode = BookingCodes::getCode(
				$timeframe->ID,
				$this->getItem()->ID,
				$this->getLocation()->ID,
				date( 'Y-m-d', $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_START ) )
			);

			// only add booking code if the booking is based on a full day timeframe
			if ( $bookingCode && $this->getMeta( 'full-day' ) == 'on' ) {
				update_post_meta(
					$this->post->ID,
					COMMONSBOOKING_METABOX_PREFIX . 'bookingcode',
					$bookingCode->getCode()
				);
			}
		}
	}

	/**
	 * Returns time from repetition-[start/end] field
	 *
	 * @param $fieldName
	 *
	 * @return string
	 */
	private function sanitizeTimeField( $fieldName ): string {
		$time       = Wordpress::getUTCDateTime();
		$fieldValue = $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_START );
		if ( $fieldName == 'end-time' ) {
			$fieldValue = $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_END );
		}
		$time->setTimestamp( $fieldValue );

		return $time->format( 'H:i' );
	}

	/**
	 * @return ?Item
	 * @throws Exception
	 */
	public function getItem(): ?Item {
		$itemId = $this->getMeta( 'item-id' );

		if ( $post = get_post( $itemId ) ) {
			return new Item( $post );
		}

		return null;
	}

	/**
	 * @return ?Location
	 * @throws Exception
	 */
	public function getLocation(): ?Location {
		$locationId = $this->getMeta( 'location-id' );
		if ( $post = get_post( $locationId ) ) {
			return new Location( $post );
		}

		return null;
	}

	/**
	 * @return string
	 */
	public function formattedBookingDate(): string {
		$date_format = commonsbooking_sanitizeHTML( get_option( 'date_format' ) );

		$startdate = date_i18n( $date_format, $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_START ) );
		$enddate   = date_i18n( $date_format, $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_END ) );

		if ( $startdate == $enddate ) {
			/* translators: %s = date in WordPress defined format */
			return sprintf( sanitize_text_field( __( ' on %s ', 'commonsbooking' ) ), $startdate );
		} else {
			/* translators: %1 = startdate, %2 = enddate in WordPress defined format */
			return sprintf( sanitize_text_field( __( ' from %1$s until %2$s ', 'commonsbooking' ) ), $startdate, $enddate );
		}
	}


	/**
	 * pickupDatetime
	 *
	 * renders the pickup date and time information and returns a formatted string
	 * this is used in templates/booking-single.php and in email-templates (configuration via admin options)
	 *
	 * @return string
	 */
	public function pickupDatetime(): string {

		$date_format = commonsbooking_sanitizeHTML( get_option( 'date_format' ) );
		$time_format = commonsbooking_sanitizeHTML( get_option( 'time_format' ) );

		$repetitionStart = $this->getStartDate();

		$date_start = date_i18n( $date_format, $repetitionStart );
		$time_start = date_i18n( $time_format, $repetitionStart );
		$time_end   = date_i18n( $time_format, $repetitionStart );

		$grid     = $this->getMeta( 'grid' );
		$full_day = $this->getMeta( 'full-day' );

		if ( $full_day == 'on' ) {
			return $date_start;
		}

		if ( $grid == 0 ) { // if grid is set to slot duration
			// If we have the grid size, we use it to calculate right time end
			$timeframeGridSize = $this->getMeta( self::START_TIMEFRAME_GRIDSIZE );
			if ( $timeframeGridSize ) {
				$grid = $timeframeGridSize;
			}
		}

		if ( $grid > 0 ) { // if grid is set to hourly (grid = 1) or a multiple of an hour
			$time_end = date_i18n( $time_format, $repetitionStart + ( 60 * 60 * $grid ) );
		}

		return $date_start . ' ' . $time_start . ' - ' . $time_end;
	}

	/**
	 * returnDatetime
	 *
	 * renders the return date and time information and returns a formatted string
	 * this is used in templates/booking-single.php and in email-templates (configuration via admin options)
	 *
	 * @return string
	 */

	public function returnDatetime(): string {
		$date_format = commonsbooking_sanitizeHTML( get_option( 'date_format' ) );
		$time_format = commonsbooking_sanitizeHTML( get_option( 'time_format' ) );

		$date_end   = date_i18n( $date_format, $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_END ) );
		$time_end   = date_i18n( $time_format, $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_END ) + 60 ); // we add 60 seconds because internal timestamp is set to hh:59
		$time_start = date_i18n( $time_format, strtotime( $this->getMeta( 'start-time' ) ) );

		$grid     = $this->getMeta( 'grid' );
		$full_day = $this->getMeta( 'full-day' );

		if ( $full_day == 'on' ) {
			return $date_end;
		}

		if ( $grid == 0 ) { // if grid is set to slot duration
			// If we have the grid size, we use it to calculate right time start
			$timeframeGridSize = $this->getMeta( self::END_TIMEFRAME_GRIDSIZE );
			if ( $timeframeGridSize ) {
				$grid = $timeframeGridSize;
			}
		}

		if ( $grid > 0 ) { // if grid is set to hourly (grid = 1) or a multiple of an hour
			$time_start = date_i18n( $time_format, $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_END ) + 1 - ( 60 * 60 * $grid ) );
		}

		return $date_end . ' ' . $time_start . ' - ' . $time_end;
	}

	public function getStartDate() {
		return $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_START );
	}

	public function getEndDate() {
		return $this->getMeta( \CommonsBooking\Model\Timeframe::REPETITION_END );
	}

	/**
	 * Returns comment text.
     *
	 * @return string
	 */
	public function returnComment(): string {
		return commonsbooking_sanitizeHTML( $this->getMeta( 'comment' ) );
	}

	/**
	 * show booking notice
	 *
	 * @return string
	 */
	public function bookingNotice(): ?string {

		$currentStatus    = $this->post->post_status;
		$cancellationTime = $this->getMeta( 'cancellation_time' );

		if ( $currentStatus == 'unconfirmed' ) {
			$noticeText = commonsbooking_sanitizeHTML( __( 'Please check your booking and click confirm booking', 'commonsbooking' ) );
		} elseif ( $currentStatus == 'confirmed' ) {
			$noticeText = commonsbooking_sanitizeHTML( Settings::getOption( COMMONSBOOKING_PLUGIN_SLUG . '_options_templates', 'booking-confirmed-notice' ) );
		}

		if ( $currentStatus == 'canceled' ) {
            if ( $cancellationTime ) {
                $cancellationTimeFormatted = Helper::FormattedDateTime( $cancellationTime );
			    $noticeText                = sprintf( commonsbooking_sanitizeHTML( __( 'Your booking has been canceled at %s.', 'commonsbooking' ) ), $cancellationTimeFormatted );
            } else {
                $noticeText = commonsbooking_sanitizeHTML( __( 'Your booking has been canceled', 'commonsbooking' ) );
            }
		}

		if ( isset( $noticeText ) ) {
			return sprintf( '<div class="cb-notice cb-booking-notice cb-status-%s">%s</div>', $currentStatus, $noticeText );
		}

		return null;
	}

	/**
	 * Return HTML Link to booking
     *
	 * @TODO: optimize booking link to support different permalink settings or set individual slug (e.g. booking instead of cb_timeframe)
	 *
	 * @return string
	 */
	public function bookingLink( $linktext = null ): string {

		// if no linktext is set we use standard text
		if ( $linktext == null ) {
			$linktext = esc_html__( 'Link to your booking', 'commonsbooking' );
		}

		return sprintf( '<a href="%1$s">%2$s</a>', add_query_arg( $this->post->post_type, $this->post->post_name, home_url( '/' ) ), $linktext );
	}

	/**
	 * return plain booking URL
	 *
	 * @return string
	 */
	public function bookingLinkUrl() {
		return add_query_arg( $this->post->post_type, $this->post->post_name, home_url( '/' ) );
	}

	/**
	 * Returns true when booking is cancelled
	 *
	 * @return bool
	 */
	public function isCancelled(): bool {
		return ( $this->post_status == 'canceled' ? : false );
	}

	/**
	 * Returns true when booking has ended
	 *
	 * @return bool
	 */
	public function isPast(): bool {
		if ( $this->getEndDate() < current_time( 'timestamp' ) ) {
			return true;
		} else {
			return false;
		}
	}

	public function getiCal(
		string $eventTitle,
		string $eventDescription
	): string {
		$calendar = new iCalendar();
		$calendar->addBookingEvent( $this, $eventTitle, $eventDescription );
		return $calendar->getCalendarData();
	}

	/**
	 * Helper to return the email signature configured in the options array
	 * 
	 * @return string
	 */
	public function getEmailSignature(): string {
		return commonsbooking_sanitizeHTML(
			Settings::getOption( 'commonsbooking_options_templates', 'emailbody_signature' )
		);
	}

    /**
     * Returns formatted user info based on the template field in settings -> templates
     *
     * @return void
     */
    public static function getFormattedUserInfo() {
        return commonsbooking_parse_template(
            Settings::getOption( COMMONSBOOKING_PLUGIN_SLUG . '_options_templates', 'user_details_template' )
        );
    }
}
