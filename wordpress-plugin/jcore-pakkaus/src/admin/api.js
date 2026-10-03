import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const BASE = '/jcore-pakkaus/v1';

export const getService = () => apiFetch( { path: `${ BASE }/service` } );

export const getSettings = () => apiFetch( { path: `${ BASE }/settings` } );

export const saveSettings = ( data ) =>
	apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } );

export const getVideos = ( query ) =>
	apiFetch( { path: addQueryArgs( `${ BASE }/videos`, query ) } );

export const optimizeVideo = ( id ) =>
	apiFetch( { path: `${ BASE }/videos/${ id }/optimize`, method: 'POST' } );

export const restoreVideo = ( id ) =>
	apiFetch( { path: `${ BASE }/videos/${ id }/restore`, method: 'POST' } );

export const optimizeAll = () =>
	apiFetch( { path: `${ BASE }/videos/optimize-all`, method: 'POST' } );
