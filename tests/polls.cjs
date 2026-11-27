const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const path = require( 'node:path' );
const { test } = require( 'node:test' );
const { runInNewContext } = require( 'node:vm' );
const { transformSync } = require( '@babel/core' );

const { code } = transformSync(
	readFileSync(
		path.join( __dirname, '..', 'assets', 'js', 'polls.js' ),
		'utf8'
	),
	{
		babelrc: false,
		configFile: false,
		plugins: [ '@babel/plugin-transform-modules-commonjs' ],
	}
);

const messages = {
	saved: 'Your answers are saved.',
	cleared: 'Your answers were removed.',
	network: 'Something went wrong.',
};

const reply = ( ok, body ) => ( { ok, json: async () => body } );

// One poll answered with `values`, its save answered with `response`.
function loadPoll( context, values, response ) {
	const ctx = {
		pollId: 7,
		responded: false,
		busy: false,
		notice: '',
		status: '',
		confirmed: false,
		...context,
	};
	const formCtx = { values, errors: {} };
	const focused = [];
	const poll = {
		querySelector: ( selector ) => ( {
			focus: () => focused.push( selector ),
		} ),
	};
	let actions;
	runInNewContext( code, {
		exports: {},
		require: ( name ) => {
			if ( name === '@wordpress/interactivity' ) {
				return {
					getContext: ( namespace ) =>
						namespace === 'openbb/forms' ? formCtx : ctx,
					getElement: () => ( { ref: { closest: () => poll } } ),
					store: ( namespace, definition ) => {
						assert.equal( namespace, 'openbb/polls' );
						actions = definition.actions;
						return {
							state: {
								restUrl: '/poll',
								nonce: 'nonce',
								messages,
							},
						};
					},
				};
			}
			assert.equal( name, '@open-bulk-buying/forms' );
			return {
				afterRender: async () => {},
				focusField: async () => {},
				setErrors: () => {},
			};
		},
		fetch: async () => response,
	} );
	return { ctx, actions, focused, form: { closest: () => poll } };
}

async function run( generator ) {
	let step = generator.next();
	while ( ! step.done ) {
		step = generator.next( await step.value );
	}
}

test( 'saving a poll on the polls view swaps its form for a confirmation', async () => {
	for ( const [ responded, values, saved, status ] of [
		[ false, { color: 'green' }, true, messages.saved ],
		[ true, { color: '' }, false, messages.cleared ],
	] ) {
		const poll = loadPoll(
			{ confirmation: true, responded },
			values,
			reply( true, { responded: saved } )
		);
		await run(
			poll.actions.submit( { preventDefault() {}, target: poll.form } )
		);
		assert.equal( poll.ctx.confirmed, true );
		assert.equal( poll.ctx.responded, saved );
		assert.equal( poll.ctx.status, status );
		assert.deepEqual( poll.focused, [
			'.openbb-poll__confirmation .openbb-notice',
		] );

		await run( poll.actions.edit() );
		assert.equal( poll.ctx.confirmed, false, 'Back to the saved answers.' );
		assert.equal( poll.focused.at( -1 ), '.openbb-poll__title' );
	}
} );

test( 'thank-you polls and failed saves keep the form', async () => {
	for ( const [ confirmation, response, status, notice ] of [
		[ false, reply( true, { responded: true } ), messages.saved, '' ],
		[
			true,
			reply( false, { message: 'This poll is closed.' } ),
			'',
			'This poll is closed.',
		],
	] ) {
		const poll = loadPoll( { confirmation }, { color: 'green' }, response );
		await run(
			poll.actions.submit( { preventDefault() {}, target: poll.form } )
		);
		assert.equal( poll.ctx.confirmed, false );
		assert.equal( poll.ctx.status, status );
		assert.equal( poll.ctx.notice, notice );
		assert.deepEqual( poll.focused, [] );
	}
} );
