// Once a bug is fixed, delete its entry and the `todo` option of its test so the test guards against regressions.
export const KNOWN_ISSUES = {};

export function todo( id ) {
	return `Known issue ${ id }: ${ KNOWN_ISSUES[ id ].summary }`;
}

export function allowLog( ...ids ) {
	return ids.map( ( id ) => {
		if ( ! KNOWN_ISSUES[ id ]?.log ) {
			throw new Error( `Known issue ${ id } has no log pattern to allow.` );
		}
		return { pattern: KNOWN_ISSUES[ id ].log, reason: id };
	} );
}
