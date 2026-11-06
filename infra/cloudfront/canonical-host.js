// CloudFront Function "canonical-host" (runtime cloudfront-js-2.0), on the
// default behaviour's Viewer request (ADR 0037). Copy this file into the
// CloudFront console as is; CanonicalHostFunctionTest runs it with node.
//
// Every address other than uselocalgoods.com (www and the old
// d4ghrpm72uczx.cloudfront.net) gets a 301 to the same path and query there.
// Laravel can't do this: CloudFront doesn't pass the visitor's host on to
// Beanstalk (ADR 0009). Without it, a page on another host posts to
// APP_URL, where the browser has no session cookie: "page expired".
// The browser keeps the #… part across the redirect, so old judge links
// (/judge-access#id.token) still sign in.
//
// Never redirected:
// - /stripe/webhook: Stripe doesn't follow redirects, so a moved webhook
//   would stop arriving. Its signature is the only check; the host isn't.
// - /up: the health check.
var CANONICAL_HOST = 'uselocalgoods.com';
var NEVER_REDIRECTED = ['/stripe/webhook', '/up'];

function handler(event) {
    var request = event.request;
    var host = request.headers.host ? request.headers.host.value.toLowerCase() : '';

    if (host === CANONICAL_HOST || NEVER_REDIRECTED.indexOf(request.uri) !== -1) {
        return request;
    }

    return {
        statusCode: 301,
        statusDescription: 'Moved Permanently',
        headers: {
            location: { value: 'https://' + CANONICAL_HOST + request.uri + queryString(request.querystring) },
            'cache-control': { value: 'max-age=3600' },
        },
    };
}

// CloudFront hands over the query already split up; put it back together.
function queryString(querystring) {
    var parts = [];

    Object.keys(querystring).forEach(function (name) {
        var param = querystring[name];
        var values = param.multiValue ? param.multiValue : [param];

        values.forEach(function (each) {
            parts.push(each.value === '' ? name : name + '=' + each.value);
        });
    });

    return parts.length ? '?' + parts.join('&') : '';
}
