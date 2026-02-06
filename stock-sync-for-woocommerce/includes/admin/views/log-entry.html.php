<?php if ( $log ) { ?>
	<h3><?php esc_html_e( 'General', 'woo-stock-sync' ); ?></h3>
	<table class="wss-log-entry-table">
		<?php foreach ( $rows as $row ) { ?>
			<tr>
				<th><?php echo esc_html( $row['label'] ); ?></th>
				<td>
					<?php if ( isset( $row['url'] ) && $row['url'] ) { ?>
						<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank">
					<?php } ?>
						<?php echo $row['value']; ?>
					<?php if ( isset( $row['url'] ) && $row['url'] ) { ?>
						</a>
					<?php } ?>
				</td>
			</tr>
		<?php } ?>
	</table>

	<h3><?php esc_html_e( 'Results', 'woo-stock-sync' ); ?></h3>
	<table class="wss-log-entry-table">
		<?php foreach ( $results as $row ) { ?>
			<tr>
				<th><?php echo esc_html( $row['site'] ); ?></th>
				<td>
					<?php if ( $row['level'] === 'success' ) { ?>
						<span class="wss-label wss-label-ok">
							<span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html( $row['msg'] ); ?>
						</span>
					<?php } else if ( $row['level'] === 'error' ) { ?>
						<span class="wss-label wss-label-error">
							<span class="dashicons dashicons-dismiss"></span> <?php echo esc_html( $row['msg'] ); ?>

							<?php if ( $row['more_url'] ) { ?>
								<a href="<?php echo esc_url( $row['more_url'] ); ?>" target="_blank"><?php esc_html_e( 'View details', 'woo-stock-sync' ); ?> &raquo;</a>
							<?php } ?>
						</span>
					<?php } else if ( $row['level'] === 'warning' ) { ?>
						<span class="wss-label wss-label-warning">
							<span class="dashicons dashicons-warning"></span> <?php echo esc_html( $row['msg'] ); ?>
						</span>
					<?php } else if ( $row['level'] === 'na' ) { ?>
						<span class="wss-label wss-label-na">
							<span class="dashicons dashicons-info"></span> <?php echo esc_html( $row['msg'] ); ?>
						</span>

						<div class="wss-help">
							<?php esc_html_e( "No log entry found. This can happen if the site was added after the event or if the URL has changed.", 'woo-stock-sync' ); ?>
						</div>
					<?php } else if ( $row['level'] === 'info' ) { ?>
						<span class="wss-label wss-label-info">
							<span class="dashicons dashicons-info"></span> <?php echo esc_html( $row['msg'] ); ?>
						</span>

						<div class="wss-help">
							<?php esc_html_e( "Syncing is still in progress. If the status doesn't update shortly, there may be a background processing issue.", 'woo-stock-sync' ); ?> <a href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=woo_stock_sync#woo_stock_sync_process_model' ); ?>" target="_blank"><?php esc_html_e( 'View settings', 'woo-stock-sync' ); ?> &raquo;</a>
						</div>
					<?php } else { ?>
						<span class="wss-label wss-label-na">
							<span class="dashicons dashicons-info"></span> <?php echo esc_html( $row['msg'] ); ?>
						</span>
					<?php } ?>
				</td>
			</tr>
		<?php } ?>
	</table>
<?php } else { ?>
	<p><?php esc_html_e( 'No log entry found.', 'woo-stock-sync' ); ?></p>
<?php } ?>
