import { appendFileSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { config } from './config.mjs';

const scenarioOf = ( file ) => path.basename( file ?? 'unknown', '.test.mjs' );

const RUNNER_TOTALS = /^(?:tests|suites|pass|fail|cancelled|skipped|todo|duration_ms) [\d.]+$/;

function errorText( error ) {
	const original = error?.cause ?? error;
	return String( original?.message ?? original ?? 'Unknown error' ).trim().slice( 0, 2500 );
}

const firstLine = ( text ) => text.split( '\n' )[ 0 ].slice( 0, 200 );

const seconds = ( ms ) => `${ ( ( ms ?? 0 ) / 1000 ).toFixed( 1 ) }s`;

// Replaces the spec reporter, which prints every failing todo test with a stack trace even on a green run.
export default async function* smokeReporter( source ) {
	const started = Date.now();
	const result = { passed: 0, failed: [], setupFailed: [], cancelled: 0, knownIssues: [], fixedKnownIssues: [], skipped: 0 };

	for await ( const event of source ) {
		const { data } = event;
		if ( event.type === 'test:stdout' || event.type === 'test:stderr' ) {
			yield data.message;
			continue;
		}
		if ( event.type === 'test:diagnostic' ) {
			if ( ! RUNNER_TOTALS.test( data.message ) ) {
				yield `  # ${ data.message }\n`;
			}
			continue;
		}
		if ( event.type !== 'test:pass' && event.type !== 'test:fail' ) {
			continue;
		}
		const scenario = scenarioOf( data.file );
		const failureType = data.details?.error?.failureType;
		const label = `${ scenario } > ${ data.name }`;
		if ( data.details?.type === 'suite' ) {
			if ( event.type === 'test:fail' && failureType === 'hookFailed' ) {
				const error = errorText( data.details.error );
				result.setupFailed.push( { scenario, error } );
				yield `✖ ${ scenario }: setup failed: ${ firstLine( error ) }\n`;
			}
			continue;
		}
		if ( event.type === 'test:fail' && failureType === 'cancelledByParent' ) {
			result.cancelled++;
		} else if ( data.skip !== undefined && data.skip !== false ) {
			result.skipped++;
			yield `○ ${ label } (skipped${ typeof data.skip === 'string' ? `: ${ data.skip }` : '' })\n`;
		} else if ( data.todo !== undefined && data.todo !== false ) {
			if ( event.type === 'test:fail' ) {
				result.knownIssues.push( { scenario, name: data.name } );
				yield `- ${ label } (known issue)\n`;
			} else {
				result.fixedKnownIssues.push( { scenario, name: data.name } );
				yield `! ${ label } (known issue now passes: remove its todo option and known-issues.mjs entry)\n`;
			}
		} else if ( event.type === 'test:pass' ) {
			result.passed++;
			yield `✔ ${ label } (${ seconds( data.details?.duration_ms ) })\n`;
		} else {
			const error = errorText( data.details?.error );
			result.failed.push( { scenario, name: data.name, error } );
			yield `✖ ${ label } (${ seconds( data.details?.duration_ms ) }): ${ firstLine( error ) }\n`;
		}
	}

	const total = ( ( Date.now() - started ) / 1000 ).toFixed( 1 );
	const logs = ( scenario ) => path.join( config.outputDir, scenario );
	const lines = [ '', '='.repeat( 72 ), `Safety Net smoke tests (PHP ${ config.php }, WordPress ${ config.wp }, plugin at ${ config.pluginDir })` ];
	lines.push( `${ result.passed } passed, ${ result.failed.length } failed, ${ result.setupFailed.length } scenario setups failed, ${ result.cancelled } not run, ${ result.skipped } skipped, ${ result.knownIssues.length } known issues, ${ result.fixedKnownIssues.length } known issues now passing, ${ total }s` );
	if ( result.knownIssues.length ) {
		lines.push( '', 'Known Safety Net issues (tests marked todo, see tests/smoke/lib/known-issues.mjs):' );
		for ( const { scenario, name } of result.knownIssues ) {
			lines.push( `  - ${ scenario } > ${ name }` );
		}
	}
	if ( result.fixedKnownIssues.length ) {
		lines.push( '', `Known issues that now pass, fixed but still marked todo${ process.env.CI ? ' (this fails the run in CI)' : '' }; remove their todo option and known-issues.mjs entry:` );
		for ( const { scenario, name } of result.fixedKnownIssues ) {
			lines.push( `  - ${ scenario } > ${ name }` );
		}
	}
	for ( const { scenario, error } of result.setupFailed ) {
		lines.push( '', `SETUP FAILED: ${ scenario }`, `  ${ error.replace( /\n/g, '\n  ' ) }`, `  Logs: ${ logs( scenario ) }` );
	}
	for ( const { scenario, name, error } of result.failed ) {
		lines.push( '', `FAILED: ${ scenario } > ${ name }`, `  ${ error.replace( /\n/g, '\n  ' ) }`, `  Logs: ${ logs( scenario ) } (debug.log, probe.jsonl, http.jsonl)` );
	}
	lines.push( '='.repeat( 72 ), '' );

	try {
		mkdirSync( config.outputDir, { recursive: true } );
		writeFileSync( path.join( config.outputDir, 'summary.json' ), JSON.stringify( { php: config.php, wp: config.wp, seconds: Number( total ), ...result }, null, 2 ) );
		if ( process.env.GITHUB_STEP_SUMMARY ) {
			const md = [ `### Safety Net smoke tests: PHP ${ config.php }, WordPress ${ config.wp }`, '', `${ result.passed } passed, ${ result.failed.length + result.setupFailed.length } failed, ${ result.knownIssues.length } known issues, ${ result.fixedKnownIssues.length } known issues now passing, ${ total }s`, '' ];
			for ( const { scenario, name, error } of [ ...result.setupFailed.map( ( f ) => ( { ...f, name: 'setup' } ) ), ...result.failed ] ) {
				md.push( `- **${ scenario } > ${ name }**: ${ firstLine( error ) }` );
			}
			for ( const { scenario, name } of result.fixedKnownIssues ) {
				md.push( `- **${ scenario } > ${ name }**: fixed but still marked todo` );
			}
			appendFileSync( process.env.GITHUB_STEP_SUMMARY, md.join( '\n' ) + '\n' );
		}
	} catch {
		// The summary files are a convenience; the exit code already carries the result.
	}

	yield lines.join( '\n' );
}
