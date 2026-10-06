import { existsSync, readFileSync } from 'node:fs';

const ENTRY_START = /^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}[^\]]*\] /;

export const LOG_CANARY = 'SN_TEST boot';

export const CRITICAL_ERROR_PAGE = /There has been a critical error on (?:this|your) website|The site is experiencing technical difficulties/i;

export function readLogEntries( file ) {
	if ( ! existsSync( file ) ) {
		return [];
	}
	const entries = [];
	for ( const line of readFileSync( file, 'utf8' ).split( '\n' ) ) {
		if ( ENTRY_START.test( line ) || entries.length === 0 ) {
			entries.push( line );
		} else {
			entries[ entries.length - 1 ] += '\n' + line;
		}
	}
	return entries.filter( ( entry ) => entry.trim() !== '' );
}

export const isFatal = ( entry ) =>
	/PHP (?:Fatal error|Parse error|Recoverable fatal error|Core error|Compile error)|Uncaught (?:[A-Za-z\\_]+(?:Error|Exception)|exception)/.test( entry ) || CRITICAL_ERROR_PAGE.test( entry );

// Warnings and database errors raised by Safety Net's own code (the helper attributes core notices); other code is not this suite's business.
export const isSafetyNetProblem = ( entry ) =>
	( /PHP (?:Warning|Notice|Deprecated|Strict Standards)/.test( entry ) && /\/safety-net\//.test( entry ) ) ||
	( /WordPress database error/.test( entry ) && /SafetyNet\\/.test( entry ) ) ||
	/SN_TEST attributed: /.test( entry );

export function findLogProblems( entries, allow = [] ) {
	const isAllowed = ( entry ) => allow.some( ( { pattern } ) => pattern.test( entry ) );
	const problems = entries.filter( ( entry ) => isFatal( entry ) || isSafetyNetProblem( entry ) );
	return {
		fatals: problems.filter( ( entry ) => isFatal( entry ) && ! isAllowed( entry ) ),
		safetyNet: problems.filter( ( entry ) => ! isFatal( entry ) && ! isAllowed( entry ) ),
		allowed: problems.filter( isAllowed ),
	};
}
