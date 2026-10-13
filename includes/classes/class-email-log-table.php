<?php
/**
 * Email log list table
 *
 * @package SafetyNet
 */

namespace SafetyNet\EmailLog;

/**
 * The list on the Email Log page: newest first, filtered by outcome and searchable.
 */
final class Email_Log_Table extends \WP_List_Table {

	/**
	 * How many entries the log holds per outcome, '' for an unknown one.
	 *
	 * @var array<string, int>
	 */
	private array $counts = array();

	/**
	 * Sets up the table for the current screen.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'plural' => 'safety-net-email-log',
				'ajax'   => false,
			)
		);

		$this->screen->set_screen_reader_content(
			array(
				'heading_views'      => esc_html__( 'Filter email log', 'safety-net' ),
				'heading_pagination' => esc_html__( 'Email log navigation', 'safety-net' ),
				'heading_list'       => esc_html__( 'Email log', 'safety-net' ),
			)
		);
	}

	/**
	 * Whether the current user can see the log.
	 *
	 * @return bool
	 */
	public function ajax_user_can() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Loads the counts and the current page of entries for the current filter and search.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$args    = current_list_args();
		$outcome = $args['outcome'] ?? '';
		$search  = $args['s'] ?? '';

		$this->counts = count_by_outcome();

		if ( '' !== $search ) {
			$total = count_entries( $outcome, $search );
		} elseif ( '' !== $outcome ) {
			$total = $this->counts[ $outcome ] ?? 0;
		} else {
			$total = array_sum( $this->counts );
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => PER_PAGE,
			)
		);

		$this->items = get_entries( $this->get_pagenum(), $outcome, $search );
	}

	/**
	 * Returns the columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'date'       => esc_html__( 'Date', 'safety-net' ),
			'recipients' => esc_html__( 'To', 'safety-net' ),
			// Screen Options never offers to hide a column keyed 'title', and this one holds the links that open an entry.
			'title'      => esc_html__( 'Subject', 'safety-net' ),
			'status'     => esc_html__( 'Status', 'safety-net' ),
			'outcome'    => esc_html__( 'Result', 'safety-net' ),
			'error'      => esc_html__( 'Error', 'safety-net' ),
		);
	}

	/**
	 * Returns the column with the row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'title';
	}

	/**
	 * Returns the links that filter the list by outcome, with their counts, which ignore the search.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$args    = current_list_args();
		$current = $args['outcome'] ?? '';
		$base    = isset( $args['s'] ) ? array( 's' => $args['s'] ) : array();
		$views   = array( 'all' => array( __( 'All', 'safety-net' ), array_sum( $this->counts ), array() ) );

		foreach ( OUTCOMES as $outcome ) {
			$views[ $outcome ] = array( outcome_label( $outcome ), $this->counts[ $outcome ] ?? 0, array( 'outcome' => $outcome ) );
		}

		$links = array();
		foreach ( $views as $view => list( $label, $count, $filter ) ) {
			$links[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( page_url( $base + $filter ) ),
				( $filter['outcome'] ?? '' ) === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $links;
	}

	/**
	 * Shows why the list is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		$args = current_list_args();

		echo '<span class="safety-net-email-log-empty">';
		if ( isset( $args['outcome'] ) || isset( $args['s'] ) ) {
			esc_html_e( 'No entries match.', 'safety-net' );
		} else {
			esc_html_e( 'No emails have been logged.', 'safety-net' );
		}
		echo '</span>';
	}

	/**
	 * Renders the pagination above or below the table; there are no bulk actions, so no bulk nonce either.
	 *
	 * @param string $which 'top' or 'bottom'.
	 *
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		if ( 'bottom' === $which && ! $this->has_items() ) {
			return;
		}
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">
			<?php $this->pagination( $which ); ?>
			<br class="clear" />
		</div>
		<?php
	}

	/**
	 * Renders one entry's row, with its status and outcome as classes.
	 *
	 * @param object $item The entry.
	 *
	 * @return void
	 */
	public function single_row( $item ) {
		printf(
			'<tr class="safety-net-email-log-row status-%1$s outcome-%2$s">',
			esc_attr( $item->status ),
			esc_attr( '' !== $item->outcome ? $item->outcome : 'unknown' )
		);
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Returns the Date cell, in the site's timezone.
	 *
	 * @param object $item The entry.
	 *
	 * @return string
	 */
	protected function column_date( $item ) {
		return esc_html( format_date( $item->logged_at ) );
	}

	/**
	 * Returns the To cell.
	 *
	 * @param object $item The entry.
	 *
	 * @return string
	 */
	protected function column_recipients( $item ) {
		return esc_html( encode_text( $item->recipients ) );
	}

	/**
	 * Returns the Subject cell, which links to the entry.
	 *
	 * @param object $item The entry.
	 *
	 * @return string
	 */
	protected function column_title( $item ) {
		return sprintf(
			'<strong><a class="row-title safety-net-email-log-view" href="%1$s" data-entry="%2$d">%3$s</a></strong>',
			esc_url( entry_url( (int) $item->id ) ),
			(int) $item->id,
			esc_html( '' !== $item->subject ? encode_text( $item->subject ) : __( '(no subject)', 'safety-net' ) )
		);
	}

	/**
	 * Returns the Status cell: what Safety Net did with the email.
	 *
	 * @param object $item The entry.
	 *
	 * @return string
	 */
	protected function column_status( $item ) {
		return esc_html( status_label( $item->status ) );
	}

	/**
	 * Returns the Result cell: what wp_mail() would do with a blocked email, or did with one let through.
	 *
	 * @param object $item The entry.
	 *
	 * @return string
	 */
	protected function column_outcome( $item ) {
		return outcome_html( $item->outcome );
	}

	/**
	 * Returns the Error cell, shortened, with the whole error in its title.
	 *
	 * @param object $item The entry.
	 *
	 * @return string
	 */
	protected function column_error( $item ) {
		if ( '' === $item->error ) {
			return '';
		}

		$short = mb_strimwidth( $item->error, 0, ERROR_PREVIEW, '…', 'UTF-8' );

		return sprintf(
			'<span class="safety-net-email-log-error"%1$s>%2$s</span>',
			$short !== $item->error ? ' title="' . esc_attr( encode_text( $item->error ) ) . '"' : '',
			esc_html( encode_text( $short ) )
		);
	}

	/**
	 * Returns the View action under the subject, which opens the entry in the dialog, or on its page without JavaScript.
	 *
	 * @param object $item        The entry.
	 * @param string $column_name The current column.
	 * @param string $primary     The primary column.
	 *
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}

		return $this->row_actions(
			array(
				'view' => sprintf(
					'<a class="safety-net-email-log-view" href="%1$s" data-entry="%2$d" aria-label="%3$s">%4$s</a>',
					esc_url( entry_url( (int) $item->id ) ),
					(int) $item->id,
					/* translators: %s: the email's subject */
					esc_attr( sprintf( __( 'View “%s”', 'safety-net' ), '' !== $item->subject ? encode_text( $item->subject ) : __( '(no subject)', 'safety-net' ) ) ),
					esc_html__( 'View', 'safety-net' )
				),
			)
		);
	}
}
