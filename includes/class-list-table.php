<?php
/**
 * Native WordPress list table for the three list tabs (review, kept, trash).
 *
 * @package Oli_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class OLIMC_List_Table extends WP_List_Table {

	/** @var string review|kept|trash */
	private $tab;

	/** @var array Filters in use. */
	public $filters = [];

	/** @var bool */
	private $review_stale = false;

	/**
	 * @param string $tab     review|kept|trash.
	 * @param array  $filters Filters (search, type, month, size).
	 */
	public function __construct( $tab, array $filters ) {
		parent::__construct(
			[
				'singular' => 'olimc-medium',
				'plural'   => 'olimc-media',
				'ajax'     => false,
				'screen'   => 'olimc-' . $tab,
			]
		);
		$this->tab     = $tab;
		$this->filters = $filters;
	}

	/**
	 * Page size from the Screen Options (default 20, 1 to 200).
	 *
	 * @return int
	 */
	public static function per_page() {
		$n = (int) get_user_option( 'olimc_per_page' );
		return ( $n >= 1 && $n <= 200 ) ? $n : 20;
	}

	/**
	 * row_actions() already prints the toggle button; keep core from adding a second one.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		return '';
	}

	public function get_columns() {
		$cols = [
			'cb'      => '<input type="checkbox" />',
			'preview' => '<span class="screen-reader-text">' . esc_html__( 'Preview', 'oli-media-cleaner' ) . '</span>',
			'file'    => esc_html__( 'File', 'oli-media-cleaner' ),
		];
		if ( 'kept' === $this->tab ) {
			$cols['why'] = esc_html__( 'Why it is kept', 'oli-media-cleaner' );
		}
		$cols['size'] = esc_html__( 'Size', 'oli-media-cleaner' );
		$cols['type'] = esc_html__( 'Type', 'oli-media-cleaner' );
		if ( 'trash' === $this->tab ) {
			$cols['date'] = esc_html__( 'Trashed on', 'oli-media-cleaner' );
		} else {
			$cols['date'] = esc_html__( 'Uploaded', 'oli-media-cleaner' );
		}
		return $cols;
	}

	protected function get_sortable_columns() {
		return [
			'file' => [ 'name', false ],
			'size' => [ 'size', false ],
			'type' => [ 'type', false ],
			'date' => [ 'trash' === $this->tab ? 'trashed' : 'date', true ],
		];
	}

	protected function get_default_primary_column_name() {
		return 'file';
	}

	/**
	 * Name the table for assistive technology (To review / Kept / Trash).
	 */
	public function display() {
		$labels = [
			'review' => __( 'To review', 'oli-media-cleaner' ),
			'kept'   => __( 'Kept', 'oli-media-cleaner' ),
			'trash'  => __( 'Trash', 'oli-media-cleaner' ),
		];
		$label  = isset( $labels[ $this->tab ] ) ? $labels[ $this->tab ] : '';
		ob_start();
		parent::display();
		$html = (string) ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core table markup; the label is escaped here.
		echo '' === $label ? $html : preg_replace( '/<table class="wp-list-table/', '<table aria-label="' . esc_attr( $label ) . '" class="wp-list-table', $html, 1 );
	}

	protected function get_table_classes() {
		return [ 'widefat', 'fixed', 'striped', 'olimc-table' ];
	}

	protected function get_bulk_actions() {
		if ( 'review' === $this->tab ) {
			return [
				'trash' => __( 'Move to trash', 'oli-media-cleaner' ),
				'keep'  => __( 'Keep', 'oli-media-cleaner' ),
			];
		}
		if ( 'kept' === $this->tab ) {
			return [ 'unkeep' => __( 'Stop keeping', 'oli-media-cleaner' ) ];
		}
		return [
			'restore' => __( 'Restore', 'oli-media-cleaner' ),
			'delete'  => __( 'Delete permanently', 'oli-media-cleaner' ),
		];
	}

	/**
	 * Load one page of rows.
	 */
	public function prepare_items() {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : ( 'trash' === $this->tab ? 'trashed' : 'date' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filters are read-only GET parameters.
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $orderby, [ 'name', 'size', 'type', 'date', 'trashed' ], true ) ) {
			$orderby = 'trash' === $this->tab ? 'trashed' : 'date';
		}
		if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) {
			$order = 'desc';
		}
		// Let core mark the active column (aria-sort, arrow) and build the toggle links.
		$_GET['orderby'] = $orderby; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['order']   = $order; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paging          = [
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => self::per_page(),
			'page'     => $page,
		];
		if ( 'review' === $this->tab ) {
			$scan_id = OLIMC_Results::published_id();
			$res     = $scan_id ? OLIMC_Results::query( $scan_id, $this->filters, $paging ) : [
				'rows'  => [],
				'total' => 0,
			];
		} else {
			$res = OLIMC_Library::query( $this->tab, $this->filters, $paging );
		}
		$this->items = $res['rows'];
		$this->set_pagination_args(
			[
				'total_items' => (int) $res['total'],
				'per_page'    => $paging['per_page'],
			]
		);
		$this->review_stale = 'review' === $this->tab && OLIMC_Scan::is_stale();
	}

	/**
	 * Native pagination, with the item count in the plugin's own plural string.
	 *
	 * @param string $which top|bottom.
	 */
	protected function pagination( $which ) {
		ob_start();
		parent::pagination( $which );
		$html  = ob_get_clean();
		$total = isset( $this->_pagination_args['total_items'] ) ? (int) $this->_pagination_args['total_items'] : 0;
		/* translators: %s: number of items */
		$label = sprintf( _n( '%s item', '%s items', $total, 'oli-media-cleaner' ), number_format_i18n( $total ) );
		$html  = preg_replace( '#(<span class="displaying-num">).*?(</span>)#s', '${1}' . addcslashes( esc_html( $label ), '\\$' ) . '${2}', $html, 1 );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core pagination markup with an escaped label.
	}

	public function no_items() {
		if ( $this->has_filters() ) {
			esc_html_e( 'No result for these filters.', 'oli-media-cleaner' );
		} elseif ( 'review' === $this->tab ) {
			esc_html_e( 'No unused media.', 'oli-media-cleaner' );
		} elseif ( 'kept' === $this->tab ) {
			esc_html_e( 'No kept media. Keep a file so it is never reported as unused.', 'oli-media-cleaner' );
		} else {
			esc_html_e( 'The trash is empty. Files you move to the trash appear here and stay restorable.', 'oli-media-cleaner' );
		}
	}

	/**
	 * @return bool
	 */
	public function has_filters() {
		foreach ( [ 'search', 'type', 'month', 'size' ] as $k ) {
			if ( ! empty( $this->filters[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param object $item Row.
	 */
	public function single_row( $item ) {
		printf(
			'<tr data-id="%1$d" data-size="%2$d" data-name="%3$s">',
			(int) $item->id,
			(int) $item->size,
			esc_attr( $this->display_name( $item ) )
		);
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	private function display_name( $item ) {
		$title = trim( (string) $item->title );
		return '' !== $title ? $title : wp_basename( (string) $item->file );
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_cb( $item ) {
		$name = $this->display_name( $item );
		printf(
			'<label class="screen-reader-text" for="olimc-cb-%1$d">%2$s</label><input type="checkbox" name="ids[]" id="olimc-cb-%1$d" value="%1$d">',
			(int) $item->id,
			esc_html( sprintf( /* translators: %s: media name */ __( 'Select “%s”', 'oli-media-cleaner' ), $name ) )
		);
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_preview( $item ) {
		$id   = (int) $item->id;
		$mime = (string) $item->mime;
		if ( 0 === strpos( $mime, 'image/' ) ) {
			// In the trash the files are offline: the preview comes from the quarantine, through an admin-only endpoint.
			$src = ( 'trash' === $this->tab && OLIMC_Quarantine::is_quarantined( $id ) ) ? OLIMC_Quarantine::thumb_url( $id ) : ( 'trash' === $this->tab && OLIMC_Trash::backend()->is_trashed( $id ) ? '' : wp_get_attachment_image_url( $id, 'thumbnail' ) );
			if ( $src ) {
				return '<img class="olimc-thumb" src="' . esc_url( $src ) . '" alt="" width="48" height="48" loading="lazy" decoding="async">';
			}
		}
		$icon = wp_mime_type_icon( $mime );
		return $icon ? '<img class="olimc-thumb olimc-thumb-icon" src="' . esc_url( $icon ) . '" alt="" width="48" height="48" loading="lazy">' : '';
	}

	/**
	 * Title, file name, dimensions and the row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_file( $item ) {
		$id    = (int) $item->id;
		$name  = $this->display_name( $item );
		$edit  = get_edit_post_link( $id );
		if ( 'trash' === $this->tab && OLIMC_Trash::backend()->is_trashed( $id ) ) {
			$edit = ''; // Hidden from the Media Library while in the trash: nothing to edit.
		}
		$new   = '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'oli-media-cleaner' ) . '</span>';
		/* translators: %s: media name; read by screen readers after the link text */
		$named = '<span class="screen-reader-text"> ' . esc_html( sprintf( __( '“%s” (opens in a new tab)', 'oli-media-cleaner' ), $name ) ) . '</span>';
		$out   = '<strong class="row-title">';
		$out  .= $edit ? '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">' . esc_html( $name ) . $new . '</a>' : esc_html( $name );
		$out  .= '</strong>';
		$meta  = wp_get_attachment_metadata( $id );
		$dims  = '';
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			/* translators: 1: width in pixels, 2: height in pixels */
			$dims = ' · ' . sprintf( __( '%1$s × %2$s px', 'oli-media-cleaner' ), number_format_i18n( (int) $meta['width'] ), number_format_i18n( (int) $meta['height'] ) );
		}
		$out .= '<br><span class="olimc-mono">' . esc_html( wp_basename( (string) $item->file ) ) . '</span>' . esc_html( $dims );
		if ( 'trash' === $this->tab && 'trash' === ( isset( $item->status ) ? $item->status : '' ) && ! get_post_meta( $id, '_olimc_trashed', true ) ) {
			$out .= '<br><span class="description">' . esc_html__( 'Former WordPress trash', 'oli-media-cleaner' ) . '</span>';
		}

		$actions = [];
		$btn     = static function ( $act, $label, $aria, $cls = '' ) use ( $id ) {
			return sprintf(
				'<button type="button" class="button-link olimc-act%1$s" data-act="%2$s" data-id="%3$d" aria-label="%4$s">%5$s</button>',
				$cls ? ' ' . esc_attr( $cls ) : '',
				esc_attr( $act ),
				$id,
				esc_attr( $aria ),
				esc_html( $label )
			);
		};
		if ( 'review' === $this->tab || 'kept' === $this->tab ) {
			$view = wp_get_attachment_url( $id );
			if ( $view ) {
				$actions['view'] = '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'oli-media-cleaner' ) . $named . '</a>';
			}
			if ( $edit ) {
				$actions['edit'] = '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">' . esc_html__( 'Edit', 'oli-media-cleaner' ) . $named . '</a>';
			}
		}
		if ( 'review' === $this->tab ) {
			/* translators: %s: media name */
			$actions['keep'] = $btn( 'keep', __( 'Keep', 'oli-media-cleaner' ), sprintf( __( 'Keep “%s”', 'oli-media-cleaner' ), $name ) );
			/* translators: %s: media name */
			$actions['trash'] = $btn( 'trash', __( 'Trash', 'oli-media-cleaner' ), sprintf( __( 'Move “%s” to the trash', 'oli-media-cleaner' ), $name ) );
		} elseif ( 'kept' === $this->tab ) {
			if ( '' === (string) $item->reason ) {
				/* translators: %s: media name */
				$actions['unkeep'] = $btn( 'unkeep', __( 'Stop keeping', 'oli-media-cleaner' ), sprintf( __( 'Stop keeping “%s”', 'oli-media-cleaner' ), $name ) );
			}
		} else {
			/* translators: %s: media name */
			$actions['restore'] = $btn( 'restore', __( 'Restore', 'oli-media-cleaner' ), sprintf( __( 'Restore “%s”', 'oli-media-cleaner' ), $name ) );
			/* translators: %s: media name */
			$actions['delete'] = $btn( 'delete', __( 'Delete permanently', 'oli-media-cleaner' ), sprintf( __( 'Delete permanently “%s”', 'oli-media-cleaner' ), $name ), 'olimc-danger' );
		}
		return $out . $this->row_actions( $actions );
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_why( $item ) {
		if ( '' === (string) $item->reason ) {
			return esc_html__( 'Kept by you', 'oli-media-cleaner' );
		}
		/* translators: %s: the protected path pattern that matched */
		return esc_html( sprintf( __( 'Matches the pattern: %s', 'oli-media-cleaner' ), $item->reason ) );
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_size( $item ) {
		return esc_html( size_format( (int) $item->size, 1 ) );
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_type( $item ) {
		$mime = (string) $item->mime;
		$sub  = strpos( $mime, '/' ) !== false ? substr( $mime, strpos( $mime, '/' ) + 1 ) : $mime;
		$sub  = preg_replace( '/^(x-|vnd\..*\.)/', '', (string) $sub );
		return esc_html( strtoupper( (string) $sub ) );
	}

	/**
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_date( $item ) {
		$format = get_option( 'date_format' );
		if ( 'trash' === $this->tab ) {
			$ts = isset( $item->trashed_at ) ? (int) $item->trashed_at : 0;
			return $ts > 0 ? esc_html( wp_date( $format, $ts ) ) : '—';
		}
		return esc_html( mysql2date( $format, (string) $item->uploaded ) );
	}

	/**
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * Filters next to the bulk actions (top only).
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">';
		$type = isset( $this->filters['type'] ) ? $this->filters['type'] : '';
		echo '<label class="screen-reader-text" for="olimc-f-type">' . esc_html__( 'All types', 'oli-media-cleaner' ) . '</label>';
		echo '<select name="type" id="olimc-f-type" form="olimc-filters">';
		$types = [
			''          => __( 'All types', 'oli-media-cleaner' ),
			'images'    => __( 'Images', 'oli-media-cleaner' ),
			'documents' => __( 'Documents', 'oli-media-cleaner' ),
			'audio'     => __( 'Audio', 'oli-media-cleaner' ),
			'video'     => __( 'Video', 'oli-media-cleaner' ),
			'other'     => __( 'Other', 'oli-media-cleaner' ),
		];
		foreach ( $types as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $type, $k, false ), esc_html( $label ) );
		}
		echo '</select>';

		$month = isset( $this->filters['month'] ) ? $this->filters['month'] : '';
		echo '<label class="screen-reader-text" for="olimc-f-month">' . esc_html__( 'All dates', 'oli-media-cleaner' ) . '</label>';
		echo '<select name="month" id="olimc-f-month" form="olimc-filters"><option value="">' . esc_html__( 'All dates', 'oli-media-cleaner' ) . '</option>';
		foreach ( $this->months() as $ym ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $ym ), selected( $month, $ym, false ), esc_html( wp_date( 'F Y', strtotime( $ym . '-01 12:00:00 UTC' ) ) ) );
		}
		echo '</select>';

		$size = isset( $this->filters['size'] ) ? $this->filters['size'] : '';
		echo '<label class="screen-reader-text" for="olimc-f-size">' . esc_html__( 'All sizes', 'oli-media-cleaner' ) . '</label>';
		echo '<select name="size" id="olimc-f-size" form="olimc-filters">';
		$sizes = [
			''   => __( 'All sizes', 'oli-media-cleaner' ),
			'1'  => __( '1 MB and larger', 'oli-media-cleaner' ),
			'5'  => __( '5 MB and larger', 'oli-media-cleaner' ),
			'20' => __( '20 MB and larger', 'oli-media-cleaner' ),
		];
		foreach ( $sizes as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $k ), selected( $size, (string) $k, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<button type="submit" class="button" form="olimc-filters">' . esc_html__( 'Filter', 'oli-media-cleaner' ) . '</button>';
		if ( $this->has_filters() ) {
			$reset = add_query_arg(
				[
					'page' => 'oli-media-cleaner',
					'tab'  => $this->tab,
				],
				admin_url( 'upload.php' )
			);
			echo ' <a href="' . esc_url( $reset ) . '" class="olimc-reset">' . esc_html__( 'Reset filters', 'oli-media-cleaner' ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * @return string[] YYYY-MM.
	 */
	private function months() {
		global $wpdb;
		if ( 'review' === $this->tab ) {
			return OLIMC_Results::months( OLIMC_Results::published_id() );
		}
		$rows = $wpdb->get_col( "SELECT DISTINCT DATE_FORMAT(post_date,'%Y-%m') AS ym FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ym DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Print the table without the nonce field of the parent class (no form is submitted).
	 */
	public function render() {
		$this->prepare_items();
		$this->display();
	}

	/**
	 * Header and footer without the "Show/Hide" extras the plugin does not need.
	 *
	 * @param string $which top|bottom.
	 */
	protected function display_tablenav( $which ) {
		echo '<div class="tablenav ' . esc_attr( $which ) . '">';
		if ( $this->has_items() ) {
			echo '<div class="alignleft actions bulkactions">';
			$this->bulk_actions( $which );
			echo '</div>';
		}
		$this->extra_tablenav( $which );
		$this->pagination( $which );
		echo '<br class="clear"></div>';
	}
}
