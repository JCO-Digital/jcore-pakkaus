import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const BASE = '/jcore-pakkaus/v1';

export const getService = () => apiFetch( { path: `${ BASE }/service` } );

export const getSettings = () => apiFetch( { path: `${ BASE }/settings` } );

export const saveSettings = ( data ) =>
	apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } );

// `type` is the list's route base: `videos` or `images`.
export const getItems = ( type, query ) =>
	apiFetch( { path: addQueryArgs( `${ BASE }/${ type }`, query ) } );

export const optimizeItem = ( type, id ) =>
	apiFetch( {
		path: `${ BASE }/${ type }/${ id }/optimize`,
		method: 'POST',
	} );

export const restoreItem = ( type, id ) =>
	apiFetch( { path: `${ BASE }/${ type }/${ id }/restore`, method: 'POST' } );

export const optimizeAll = ( type ) =>
	apiFetch( { path: `${ BASE }/${ type }/optimize-all`, method: 'POST' } );

export const checkServing = () =>
	apiFetch( { path: `${ BASE }/images/serving` } );
