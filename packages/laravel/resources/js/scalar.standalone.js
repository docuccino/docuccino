/*!
 * Docuccino — bundled Scalar API Reference (standalone).
 *
 * This file is served LOCALLY by the runtime viewer endpoint
 * (GET {viewer.route}/assets/scalar.js) so the docs page never reaches a runtime CDN. The real
 * Scalar standalone build (@scalar/api-reference) is vendored in here by the package build/release
 * step; this committed placeholder keeps the asset route, its content-type, and the "no CDN by
 * default" contract testable without shipping the multi-megabyte bundle in source control.
 *
 * Opt into the CDN instead (e.g. for local experimentation) with viewer.cdn => true.
 */
(function () {
  var el = document.getElementById('api-reference');
  if (!el) {
    return;
  }
  // The vendored Scalar bundle hydrates #api-reference from its data-url. Until it is dropped in,
  // this placeholder renders a minimal notice so the endpoint is self-describing.
  var url = el.getAttribute('data-url') || '';
  el.insertAdjacentHTML(
    'afterend',
    '<noscript>API specification: ' + url + '</noscript>'
  );
})();
