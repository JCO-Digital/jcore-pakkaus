const assert = require( 'node:assert/strict' );
const { createRequire } = require( 'node:module' );
const path = require( 'node:path' );
const test = require( 'node:test' );

// Exercise the copy actually used by the WordPress build's glob matching.
const pluginRequire = createRequire(
	path.resolve( __dirname, '../wordpress-plugin/jcore-pakkaus/package.json' )
);
const scriptsRequire = createRequire( pluginRequire.resolve( '@wordpress/scripts/package.json' ) );
const globRequire = createRequire( scriptsRequire.resolve( 'fast-glob' ) );
const matcherRequire = createRequire( globRequire.resolve( 'micromatch' ) );
const braces = matcherRequire( 'braces' );
const depthError = { name: 'SyntaxError', message: 'Pattern nesting exceeds the limit of 128' };

test( 'normal nested patterns, ranges and parentheses retain their behavior', () => {
	assert.deepEqual( braces.expand( 'src/{a,{b,c}}/{1..3}.js' ), [
		'src/a/1.js', 'src/a/2.js', 'src/a/3.js',
		'src/b/1.js', 'src/b/2.js', 'src/b/3.js',
		'src/c/1.js', 'src/c/2.js', 'src/c/3.js',
	] );
	assert.equal( braces.compile( 'src/{a,b}.js' ), 'src/(a|b).js' );
	assert.equal( braces.stringify( braces.parse( '({a,b})' ) ), '({a,b})' );
} );

for ( const [ open, close ] of [ [ '{', '}' ], [ '(', ')' ] ] ) {
	test( `deep ${ open } patterns fail before recursive processing`, () => {
		const input = open.repeat( 2000 ) + 'a' + close.repeat( 2000 );
		for ( const method of [ 'parse', 'create', 'compile', 'expand', 'stringify' ] ) {
			assert.throws( () => braces[ method ]( input ), depthError );
		}
		assert.throws( () => braces.expand( input, { rangeLimit: false } ), depthError );
	} );
}

test( 'direct AST input cannot bypass the nesting bound', () => {
	for ( const method of [ 'compile', 'expand', 'stringify' ] ) {
		const root = { type: 'root', nodes: [] };
		let parent = root;
		for ( let depth = 0; depth < 2000; depth++ ) {
			const node = { type: 'paren', nodes: [], parent };
			parent.nodes.push( node );
			parent = node;
		}
		parent.nodes.push( { type: 'text', value: 'a' } );
		assert.throws( () => braces[ method ]( root ), depthError );
	}
} );
