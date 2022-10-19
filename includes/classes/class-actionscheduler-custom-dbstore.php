<?php

class ActionScheduler_Custom_DBStore extends ActionScheduler_DBStore {

	protected function claim_actions( $claim_id, $limit, \DateTime $before_date = null, $hooks = array(), $group = '' ) {

		/** @var \wpdb $wpdb */
		global $wpdb;

		$now  = as_get_datetime_object();
		$date = is_null( $before_date ) ? $now : clone $before_date;

		// can't use $wpdb->update() because of the <= condition.
		$update = "UPDATE {$wpdb->actionscheduler_actions} SET claim_id=%d, last_attempt_gmt=%s, last_attempt_local=%s";
		$params = array(
			$claim_id,
			$now->format( 'Y-m-d H:i:s' ),
			current_time( 'mysql' ),
		);

		$where    = 'WHERE claim_id = 0 AND scheduled_date_gmt <= %s AND status=%s AND hook != "woocommerce_scheduled_subscription_payment" AND hook != "woocommerce_scheduled_subscription_payment_retry"';
		$params[] = $date->format( 'Y-m-d H:i:s' );
		$params[] = self::STATUS_PENDING;

		if ( ! empty( $hooks ) ) {
			$remove_these = array( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry' );
			$hooks = array_diff( $hooks, $remove_these );

			$placeholders = array_fill( 0, count( $hooks ), '%s' );
			$where       .= ' AND hook IN (' . join( ', ', $placeholders ) . ')';
			$params       = array_merge( $params, array_values( $hooks ) );
		}

		if ( ! empty( $group ) ) {

			$group_id = $this->get_group_id( $group, false );

			// throw exception if no matching group found, this matches ActionScheduler_wpPostStore's behaviour.
			if ( empty( $group_id ) ) {
				/* translators: %s: group name */
				throw new InvalidArgumentException( sprintf( __( 'The group "%s" does not exist.', 'woocommerce' ), $group ) );
			}

			$where   .= ' AND group_id = %d';
			$params[] = $group_id;
		}

		/**
		 * Sets the order-by clause used in the action claim query.
		 *
		 * @since 3.4.0
		 *
		 * @param string $order_by_sql
		 */
		$order    = apply_filters( 'action_scheduler_claim_actions_order_by', 'ORDER BY attempts ASC, scheduled_date_gmt ASC, action_id ASC' );
		$params[] = $limit;

		$sql           = $wpdb->prepare( "{$update} {$where} {$order} LIMIT %d", $params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$rows_affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $rows_affected ) {
			throw new \RuntimeException( __( 'Unable to claim actions. Database error.', 'action-scheduler' ) );
		}

		return (int) $rows_affected;
	}

}
