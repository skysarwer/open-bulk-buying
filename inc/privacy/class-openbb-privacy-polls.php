<?php
/**
 * Poll answers (the openbb_poll_responses and openbb_poll_answers tables) in the WP
 * privacy tools.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

/**
 * Export: one item per poll the member answered, with the poll's title and cycle and
 * each answer, labelled in the member's language. Answers to archived questions are
 * included: they are the member's personal data too.
 *
 * Erase: deletes every poll answer of the member on this site, free text included.
 * Nothing is retained: the co-op has no duty to keep poll answers, and free text can
 * identify someone, so answers aren't anonymised either. Counts in poll results drop
 * accordingly.
 */
class OPENBB_Privacy_Polls extends OPENBB_Privacy_Provider {

	public function id(): string {
		return 'polls';
	}

	public function label(): string {
		return __( 'Co-op poll answers', 'open-bulk-buying' );
	}

	public function export( WP_User $user, int $page ): array {
		$language = (string) openbb_get_member_field( $user->ID, 'language' );
		$language = '' !== $language ? $language : null;

		$items = array();
		foreach ( OPENBB_Poll_Responses::for_user( $user->ID ) as $response ) {
			$poll  = $response['poll'];
			$cycle = OPENBB_Cycles::get( $poll->cycle_id );
			$data  = array(
				array(
					'name'  => __( 'Poll', 'open-bulk-buying' ),
					'value' => $poll->get_title( $language ),
				),
				array(
					'name'  => __( 'Cycle', 'open-bulk-buying' ),
					'value' => $cycle ? $cycle->name : '',
				),
				array(
					'name'  => __( 'Answered', 'open-bulk-buying' ),
					'value' => $response['updated_at'],
				),
			);
			foreach ( $poll->questions_by_key() as $key => $question ) {
				if ( array_key_exists( $key, $response['answers'] ) ) {
					$data[] = array(
						'name'  => openbb_localized( $question['label'], $language ),
						'value' => self::answer_text( $question, $response['answers'][ $key ], $language ),
					);
				}
			}
			$items[] = $this->item( $this->id() . '-' . $poll->id . '-' . $user->ID, $data );
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public function erase( WP_User $user, int $page ): array {
		$removed = OPENBB_Poll_Responses::delete_for_user( $user->ID );

		return array(
			'items_removed'  => (int) $removed > 0,
			'items_retained' => false,
			'messages'       => false === $removed ? array( __( 'Poll answers could not be deleted.', 'open-bulk-buying' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * An answer as text.
	 *
	 * @param mixed $value As returned by OPENBB_Poll_Responses::get_answers().
	 */
	public static function answer_text( array $question, $value, ?string $language = null ): string {
		switch ( $question['type'] ) {
			case OPENBB_Field_Schema::TYPE_BOOLEAN:
				return $value ? __( 'Yes', 'open-bulk-buying' ) : __( 'No', 'open-bulk-buying' );

			case OPENBB_Field_Schema::TYPE_OPTIONS:
				$labels = OPENBB_Field_Schema::option_labels( $question, $language );

				return implode(
					'; ',
					array_map(
						static function ( string $key ) use ( $labels ): string {
							return $labels[ $key ] ?? $key;
						},
						(array) $value
					)
				);

			default:
				return (string) $value;
		}
	}
}
