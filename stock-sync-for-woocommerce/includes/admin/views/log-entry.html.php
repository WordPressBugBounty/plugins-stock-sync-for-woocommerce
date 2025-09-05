<?php if ( $log ) { ?>
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
<?php } else { ?>
	<p><?php esc_html_e( 'No log entry found.', 'woo-stock-sync' ); ?></p>
<?php } ?>
