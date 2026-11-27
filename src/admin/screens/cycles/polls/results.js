import {
	Button,
	Notice,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { ErrorNotice, Loading } from '../../../components';
import { request, requestList } from '../../../utils/api';
import {
	answerText,
	formatDateTime,
	isStars,
	optionLabels,
	questionLabel,
	responsesLabel,
} from './utils';

const PER_PAGE = 20;

/**
 * Counts for one question: how many answered, and for choices how many chose
 * each one, as a share of those who answered the question.
 *
 * @param {Object} props
 * @param {Object} props.question
 * @param {Object} props.tally    { answered, counts, average } from the API.
 */
function QuestionResult( { question, tally = { answered: 0 } } ) {
	const { answered, counts } = tally;
	let rows = [];
	if ( question.type === 'boolean' ) {
		rows = [
			[ '1', __( 'Yes', 'open-bulk-buying' ) ],
			[ '0', __( 'No', 'open-bulk-buying' ) ],
		];
	} else if ( question.type === 'options' ) {
		rows = Object.entries( optionLabels( question ) );
	}

	return (
		<section className="openbb-poll-results__question">
			<h4>
				{ questionLabel( question ) }{ ' ' }
				{ question.archived && (
					<span className="openbb-badge">
						{ __( 'Archived', 'open-bulk-buying' ) }
					</span>
				) }
			</h4>
			<p className="openbb-muted">
				{ sprintf(
					/* translators: %d: number of members who answered the question. */
					_n(
						'%d member answered',
						'%d members answered',
						answered,
						'open-bulk-buying'
					),
					answered
				) }
				{ question.type === 'text' &&
					answered > 0 &&
					' ' +
						__(
							'Their answers are in the table below.',
							'open-bulk-buying'
						) }
			</p>
			{ isStars( question ) &&
				tally.average !== null &&
				tally.average !== undefined && (
					<p className="openbb-poll-results__average">
						{ sprintf(
							/* translators: %s: average rating, e.g. 4.2. */
							__( 'Average: %s out of 5', 'open-bulk-buying' ),
							tally.average
						) }
					</p>
				) }
			{ rows.length > 0 && (
				<ul className="openbb-poll-results__bars">
					{ rows.map( ( [ key, label ] ) => {
						const count = counts?.[ key ] || 0;
						const percent = answered
							? Math.round( ( count / answered ) * 100 )
							: 0;
						return (
							<li key={ key }>
								<span className="openbb-poll-results__label">
									{ label }
								</span>
								<span
									className="openbb-poll-results__bar"
									aria-hidden="true"
								>
									<span style={ { width: `${ percent }%` } } />
								</span>
								<span className="openbb-poll-results__count">
									{ sprintf(
										/* translators: 1: number of members, 2: percentage. */
										__( '%1$d (%2$d%%)', 'open-bulk-buying' ),
										count,
										percent
									) }
								</span>
							</li>
						);
					} ) }
				</ul>
			) }
		</section>
	);
}

/**
 * Who answered what, a page at a time, most recently changed first.
 *
 * @param {Object} props
 * @param {Object} props.poll
 */
function Responses( { poll } ) {
	const [ page, setPage ] = useState( 1 );
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let current = true;
		requestList( `/polls/${ poll.id }/responses`, {
			page,
			per_page: PER_PAGE,
		} )
			.then( ( result ) => current && setState( result ) )
			.catch( ( err ) => current && setError( err ) );
		return () => {
			current = false;
		};
	}, [ poll.id, page ] );

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! state ) {
		return <Loading />;
	}
	if ( ! state.items.length ) {
		return <p>{ __( 'Nobody has answered yet.', 'open-bulk-buying' ) }</p>;
	}

	return (
		<>
			<div className="openbb-poll-results__table">
				<table className="widefat striped">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Member', 'open-bulk-buying' ) }
							</th>
							<th scope="col">
								{ __( 'Last changed', 'open-bulk-buying' ) }
							</th>
							{ poll.questions.map( ( question ) => (
								<th scope="col" key={ question.key }>
									{ questionLabel( question ) }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ state.items.map( ( item ) => (
							<tr key={ item.member.id }>
								<th scope="row">
									{ item.member.name }
									{ item.member.status !== 'active' && (
										<>
											{ ' ' }
											<span className="openbb-badge openbb-badge--muted">
												{ item.member.status_label ||
													__(
														'Not a member',
														'open-bulk-buying'
													) }
											</span>
										</>
									) }
									{ item.member.email && (
										<div className="openbb-muted">
											{ item.member.email }
										</div>
									) }
								</th>
								<td>{ formatDateTime( item.updated_at ) }</td>
								{ poll.questions.map( ( question ) => (
									<td key={ question.key }>
										{ answerText(
											question,
											item.answers[ question.key ]
										) || '—' }
									</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ state.totalPages > 1 && (
				<HStack justify="flex-start" spacing={ 3 }>
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						accessibleWhenDisabled
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous', 'open-bulk-buying' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: number of pages. */
							__( 'Page %1$d of %2$d', 'open-bulk-buying' ),
							page,
							state.totalPages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= state.totalPages }
						accessibleWhenDisabled
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next', 'open-bulk-buying' ) }
					</Button>
				</HStack>
			) }
		</>
	);
}

/**
 * The Results tab of a poll: counts per question, then each member's answers.
 * Staff only, and never anonymous.
 *
 * @param {Object} props
 * @param {Object} props.poll
 */
export default function PollResults( { poll } ) {
	const [ tally, setTally ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let current = true;
		request( `/polls/${ poll.id }/results` )
			.then( ( result ) => current && setTally( result ) )
			.catch( ( err ) => current && setError( err ) );
		return () => {
			current = false;
		};
	}, [ poll.id ] );

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! tally ) {
		return <Loading />;
	}

	return (
		<div className="openbb-poll-results">
			<p className="openbb-poll-results__total">
				<strong>{ responsesLabel( tally.responses ) }</strong>
			</p>
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Answers are not anonymous: the table below shows who answered what, and members are told that staff can see their answers. If a member’s personal data is erased, their answers are deleted and these counts drop.',
					'open-bulk-buying'
				) }
			</Notice>
			{ poll.questions.map( ( question ) => (
				<QuestionResult
					key={ question.key }
					question={ question }
					tally={ tally.questions[ question.key ] }
				/>
			) ) }
			<h4 className="openbb-section-title">
				{ __( 'Individual answers', 'open-bulk-buying' ) }
			</h4>
			<Responses poll={ poll } />
		</div>
	);
}
