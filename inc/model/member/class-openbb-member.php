<?php
/**
 * A WordPress user with a co-op membership on this site. Datetimes are UTC.
 *
 * For permissions, check capabilities rather than status or roles (see OPENBB_Access).
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Member extends OPENBB_Entity {

	public $user_id           = 0;
	public $email             = '';
	public $first_name        = '';
	public $last_name         = '';
	public $display_name      = '';
	public $phone             = '';
	public $language          = '';
	public $status            = '';
	/** @var string[] Team roles held. */
	public $roles             = array();
	public $joined_at         = null;
	public $rules_version     = 0;
	public $rules_accepted_at = null;

	/**
	 * Null when the user doesn't exist or isn't a member on this site.
	 */
	public static function get( int $user_id ): ?self {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$data = array(
			'user_id'      => $user->ID,
			'email'        => $user->user_email,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'display_name' => $user->display_name,
		);
		foreach ( array_keys( openbb_member_meta_fields() ) as $field ) {
			$data[ $field ] = openbb_get_member_field( $user->ID, $field );
		}
		$data['status'] = OPENBB_Access::sanitize_status( $data['status'] );
		$data['roles']  = OPENBB_Access::get_roles( $user->ID );

		return '' === $data['status'] ? null : new self( $data );
	}

	protected function cast(): void {
		$this->user_id           = (int) $this->user_id;
		$this->joined_at         = '' === $this->joined_at ? null : $this->joined_at;
		$this->rules_version     = (int) $this->rules_version;
		$this->rules_accepted_at = '' === $this->rules_accepted_at ? null : $this->rules_accepted_at;
	}

	/**
	 * A current member, whose roles apply.
	 */
	public function is_active(): bool {
		return OPENBB_Access::STATUS_ACTIVE === $this->status;
	}

	/**
	 * Signed up and awaiting approval.
	 */
	public function is_applicant(): bool {
		return OPENBB_Access::STATUS_APPLICANT === $this->status;
	}

	/**
	 * An applicant who was turned down.
	 */
	public function is_declined(): bool {
		return OPENBB_Access::STATUS_DECLINED === $this->status;
	}

	/**
	 * $required_version_id comes from OPENBB_Rules::get_required_version_id(); 0 means no rules are published yet.
	 */
	public function has_accepted_rules( int $required_version_id ): bool {
		return $required_version_id > 0 && $this->rules_version >= $required_version_id;
	}
}
