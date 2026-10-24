<?php
/**
 * Shared signup/login code input. $view: id, code_length.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$openbb_id = $view['id'];
?>
<div class="openbb-field openbb-field--code" data-openbb-field data-name="code" data-kind="text" data-required data-wp-class--has-error="openbb/forms::context.errors.code">
	<label class="openbb-field__label" for="<?php echo esc_attr( $openbb_id ); ?>-code"><?php esc_html_e( 'Code', 'open-bulk-buying' ); ?></label>
	<input
		type="text"
		class="openbb-input openbb-code"
		id="<?php echo esc_attr( $openbb_id ); ?>-code"
		name="code"
		inputmode="numeric"
		autocomplete="one-time-code"
		pattern="[0-9]*"
		required
		aria-describedby="<?php echo esc_attr( $openbb_id ); ?>-code-intro <?php echo esc_attr( $openbb_id ); ?>-code-error"
		style="--openbb-code-length: <?php echo (int) $view['code_length']; ?>"
		data-autofocus
		data-wp-on--input="actions.codeInput"
		data-wp-watch="openbb/forms::callbacks.syncInvalid"
	>
	<p class="openbb-field__error" id="<?php echo esc_attr( $openbb_id ); ?>-code-error" data-wp-bind--hidden="openbb/forms::!context.errors.code" data-wp-text="openbb/forms::context.errors.code"></p>
</div>
