<?php
/**
 * Database-free membership email checks: php tests/membership-emails.php.
 *
 * WordPress boundary functions are stubbed; models, sending service, templates
 * and the bundled French catalog are real.
 */

define( 'ABSPATH', dirname( __DIR__, 4 ) . DIRECTORY_SEPARATOR );
define( 'WPINC', 'wp-includes' );
define( 'OPENBB_UNIT_AMOUNT_DEFAULT', 1 );
define( 'OPENBB_UNIT_TYPE_DEFAULT', 'unit' );
require_once ABSPATH . 'wp-includes/compat.php';
require_once ABSPATH . 'wp-includes/pomo/mo.php';
require_once __DIR__ . '/../inc/model/class-openbb-entity.php';
require_once __DIR__ . '/../inc/model/order/class-openbb-order.php';
require_once __DIR__ . '/../inc/model/order/class-openbb-order-line.php';
require_once __DIR__ . '/../inc/emails/class-openbb-emails.php';
require_once __DIR__ . '/../inc/emails/class-openbb-order-emails.php';

$catalog = new MO();
if ( ! $catalog->import_from_file( __DIR__ . '/../languages/open-bulk-buying-fr_CA.mo' ) ) {
	throw new RuntimeException( 'Cannot load the bundled French catalog.' );
}
$locale = 'en_US';
$locales = array();
$mail = array();
$signature_override = null;

function apply_filters( $name, $value ) {
	global $signature_override;
	return 'openbb_email_signature' === $name && null !== $signature_override ? $signature_override : $value;
}
function wp_login_url() { return 'https://example.test/login'; }
function wp_lostpassword_url( $page = '' ) { return 'https://example.test/reset'; }
function esc_html__( $value, $domain ) { return esc_html( __( $value, $domain ) ); }

function __( $text, $domain ) {
	global $locale, $catalog;
	return 'fr_CA' === $locale ? $catalog->translate( $text ) : $text;
}
function get_locale() { global $locale; return $locale; }
function get_available_languages() { return array( 'fr_CA' ); }
function switch_to_locale( $next ) {
	global $locale, $locales;
	if ( $next === $locale ) { return false; }
	$locales[] = $locale;
	$locale = $next;
	return true;
}
function restore_previous_locale() { global $locale, $locales; $locale = array_pop( $locales ); }
function get_userdata( $id ) { return (object) array( 'display_name' => 'Jane <script>alert(1)</script>', 'user_email' => 'jane@example.test' ); }
function get_bloginfo( $key ) { return 'Food &amp; Friends'; }
function wp_specialchars_decode( $value, $flags ) { return html_entity_decode( $value, $flags, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wp_mail( $to, $subject, $message, $headers ) { global $mail; $mail[] = compact( 'to', 'subject', 'message', 'headers' ); return true; }
function get_option( $name ) { return 'date_format' === $name ? 'Y-m-d' : 'H:i'; }
function get_date_from_gmt( $value, $format ) { return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'America/Toronto' ) )->format( $format ); }
function openbb_format_money( $amount, $currency ) { return $currency . ' ' . number_format( $amount / ( 'JPY' === $currency ? 1 : 100 ), 'JPY' === $currency ? 0 : 2 ); }
function number_format_i18n( $amount, $decimals ) { return number_format( $amount, $decimals ); }
function openbb_format_unit( $amount, $unit ) { return "$amount $unit"; }

class Membership_Email_Order extends OPENBB_Order {
	public $test_lines = array();
	public function get_lines(): array { return $this->test_lines; }
}
class OPENBB_Cycles {
	public static function get( int $id ) { return (object) array( 'name' => 'Fall groceries' ); }
}
function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function contains( string $haystack, string $needle ): bool { return false !== strpos( $haystack, $needle ); }
function membership_order(): Membership_Email_Order {
	$order = new Membership_Email_Order( array(
		'id' => 42, 'user_id' => 7, 'status' => 'submitted', 'currency' => 'CAD', 'language' => 'en',
		'total' => 2500, 'submitted_at' => '2026-11-01 15:00:00', 'updated_at' => '2026-11-03 15:00:00',
		'member_note' => "ET-123 <img src=x>\nSecond line", 'staff_note' => 'PRIVATE STAFF NOTE', 'aid_reason' => 'PRIVATE AID REASON',
	) );
	$order->test_lines = array( new OPENBB_Order_Line( array(
		'id' => 1, 'line_type' => 'membership_fee', 'product_name' => 'Annual membership <b>snapshot</b>', 'qty_requested' => 1, 'unit_price' => 2500,
	) ) );
	return $order;
}

$claim = membership_order();
OPENBB_Order_Emails::on_saved( $claim, null );
check( count( $mail ) === 1, 'Claim should send one email.' );
check( contains( $mail[0]['subject'], 'awaiting verification' ), 'Claim subject must indicate pending verification.' );
check( contains( $mail[0]['message'], 'not a payment receipt' ), 'Claim must not imply receipt.' );
check( contains( $mail[0]['message'], 'Claimed amount' ), 'Claim amount must be labeled.' );
check( ! contains( $mail[0]['message'], 'Paid on' ), 'Claim must not show payment date.' );

$paid = clone $claim;
$paid->status = 'fulfilled';
$paid->paid_at = '2026-11-02 15:00:00';
$paid->paid_amount = 3000;
$paid->paid_currency = 'JPY';
OPENBB_Order_Emails::on_saved( $paid, $claim );
check( count( $mail ) === 2, 'Confirmation should send once.' );
check( contains( $mail[1]['subject'], 'Membership payment confirmed (#42)' ), 'Confirmation subject should be membership-specific.' );
check( contains( $mail[1]['message'], 'JPY 3,000' ), 'Receipt must use settled amount/currency, not order total or current settings.' );
check( contains( $mail[1]['message'], '2026-11-02 10:00' ), 'Receipt must show localized paid_at, not updated_at.' );
check( ! contains( $mail[1]['message'], '2026-11-03' ), 'Receipt must not substitute updated_at.' );
check( contains( $mail[1]['message'], 'Keep this email as your payment receipt' ), 'Confirmed payment must explicitly be a receipt.' );
OPENBB_Order_Emails::on_saved( $paid, clone $paid );
check( count( $mail ) === 2, 'Repeated confirmation must be silent.' );
OPENBB_Order_Emails::on_saved( $paid, null );
check( count( $mail ) === 3, 'Direct recorded payment must send a receipt.' );

$rejected = clone $claim;
$rejected->status = 'cancelled';
OPENBB_Order_Emails::on_saved( $rejected, $claim );
check( contains( $mail[3]['subject'], 'claim rejected' ), 'Rejection subject must be explicit.' );
check( contains( $mail[3]['message'], 'not a payment receipt' ), 'Rejected claim must not imply receipt.' );
check( contains( $mail[3]['message'], 'contact the co-op' ), 'Rejected claim needs next steps.' );

$updated = clone $claim;
$updated->member_note = 'Corrected reference';
OPENBB_Order_Emails::on_saved( $updated, $claim );
check( contains( $mail[4]['subject'], 'Membership payment updated' ), 'Updates must not use grocery wording.' );
check( contains( $mail[4]['message'], 'Corrected reference' ), 'Updates must show the current reference.' );
OPENBB_Order_Emails::on_saved( $updated, clone $updated );
check( count( $mail ) === 5, 'Unchanged pending claim must be silent.' );

$no_reference = clone $paid;
$no_reference->member_note = '';
$render = OPENBB_Emails::render( 'order-event', array( 'order' => $no_reference, 'event' => 'fulfilled', 'changes' => array() ) );
check( ! contains( $render['message'], '>Reference<' ), 'Empty reference should be omitted.' );
foreach ( $mail as $item ) {
	check( $item['to'] === 'jane@example.test', 'Email should go to the owning member.' );
	check( in_array( 'Content-Type: text/html; charset=UTF-8', $item['headers'], true ), 'Email must be HTML.' );
	check( contains( $item['message'], 'Annual membership &lt;b&gt;snapshot&lt;/b&gt;' ), 'Fee description must use escaped snapshot.' );
	check( contains( $item['message'], 'Jane &lt;script&gt;' ), 'Member name must be escaped.' );
	check( contains( $item['message'], 'not a grocery order or confirmation of membership approval' ), 'Payment must not imply membership approval.' );
	check( contains( $item['message'], 'Thank you!<br />' . "\nFood &amp; Friends" ), 'Membership email must include the shared signature.' );
	foreach ( array( '<script', '<img', 'PRIVATE', '>Package<', '>Quantity<', '>Own container<', '>Container credit<', '>Donation<', '>Financial aid<', '>Subtotal<' ) as $excluded ) {
		check( ! contains( $item['message'], $excluded ), 'Unexpected receipt content: ' . $excluded );
	}
}
check( contains( $mail[1]['message'], 'ET-123 &lt;img src=x&gt;<br' ), 'Reference must be escaped and preserve line breaks.' );
$paid->language = 'fr';
OPENBB_Order_Emails::on_saved( $paid, null );
check( contains( $mail[5]['subject'], 'Paiement des frais d’adhésion confirmé' ), 'Subject must use the member language.' );
check( contains( $mail[5]['message'], 'Montant payé' ), 'Receipt fields must be translated.' );
check( contains( $mail[5]['message'], 'Conservez ce courriel comme reçu de paiement.' ), 'Receipt copy must be translated.' );
check( contains( $mail[5]['message'], 'Merci!<br />' . "\nFood &amp; Friends" ), 'Signature must use the member language.' );
check( 'en_US' === $locale && ! $locales, 'Member locale must be restored after sending.' );
$groceries = clone $claim;
$groceries->cycle_id = 9;
$groceries->test_lines[] = new OPENBB_Order_Line( array(
	'id' => 2, 'product_name' => 'Apples', 'qty_requested' => 2, 'unit_price' => 250,
) );
$groceries->subtotal = 500;
$groceries->total = 3000;
$render = OPENBB_Emails::render( 'order-event', array( 'order' => $groceries, 'event' => 'created', 'changes' => array() ) );
check( contains( $render['subject'], 'Order created' ), 'Mixed grocery orders must keep order subjects.' );
foreach ( array( 'Fall groceries', '>Package<', '>Quantity<', '>Container credit<', '>Subtotal<', 'Apples', 'Annual membership' ) as $expected ) {
	check( contains( $render['message'], $expected ), 'Mixed grocery receipt lost content: ' . $expected );
}
check( ! contains( $render['message'], 'This email concerns your membership fee only' ), 'Mixed orders must not use the standalone template.' );
check( contains( $render['message'], 'Thank you!<br />' . "\nFood &amp; Friends" ), 'Grocery receipts must include the shared signature.' );
$render = OPENBB_Emails::render( 'order-event', array( 'order' => $groceries, 'event' => 'updated', 'changes' => array( array( 'Apples', 'Before apples', 'After apples' ) ) ) );
foreach ( array( 'Order updated', '>Before<', '>After<', 'Before apples', 'After apples' ) as $expected ) {
	check( contains( $render['subject'] . $render['message'], $expected ), 'Grocery update diff lost content: ' . $expected );
}
$plain = OPENBB_Emails::render( 'account-exists' );
check( str_ends_with( $plain['message'], "\n\nThank you!\nFood & Friends" ), 'Plain-text signature must preserve newlines.' );
$html = OPENBB_Emails::render( 'signup-code', array( 'code' => '123456', 'minutes' => 10 ) );
check( str_ends_with( $html['message'], '<p>Thank you!<br />' . "\nFood &amp; Friends</p>" ), 'HTML fragment signature must be escaped.' );
$signature_override = "Kind regards,\nTeam <Friends>";
foreach ( array( 'order-event', 'account-exists', 'signup-code' ) as $template ) {
	$data = 'order-event' === $template ? array( 'order' => $paid, 'event' => 'fulfilled', 'changes' => array() ) : array( 'code' => '123456', 'minutes' => 10 );
	$render = OPENBB_Emails::render( $template, $data );
	check( contains( $render['message'], 'Kind regards,' ), 'Custom signature must reach every format.' );
	check( contains( $render['message'], 'account-exists' === $template ? 'Team <Friends>' : 'Team &lt;Friends&gt;' ), 'Custom signature escaping must match the format.' );
	$signature_override = '';
	$unsigned = OPENBB_Emails::render( $template, $data );
	check( ! contains( $unsigned['message'], 'Kind regards,' ), 'Empty signature must disable it.' );
	$signature_override = "Kind regards,\nTeam <Friends>";
}
echo "Membership email and shared signature checks passed, including HTML/plain text, customization, disabling, escaping, and French.\n";
