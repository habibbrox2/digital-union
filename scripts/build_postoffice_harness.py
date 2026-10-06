#!/usr/bin/env python3
"""Rebuild scripts/postoffice_dropdown_harness.html.

The harness is a standalone page (all CSS inlined, API stubbed) built from the
real Post Office row of templates/partials/forms/address_fields.twig plus the
current contents of templates/common/address_fields_script.twig, so it can be
used to verify the EN/BN post-office autocomplete behaviour in a browser.

Usage (from the repo root):  python scripts/build_postoffice_harness.py
"""
import re

partial = open('templates/partials/forms/address_fields.twig', encoding='utf-8').read()
script = open('templates/common/address_fields_script.twig', encoding='utf-8').read()
js = re.search(r'<script>(.*)</script>', script, re.S).group(1)

# the preview server only serves this single file, so inline the styles
forms_css = open('public/assets/css/forms.css', encoding='utf-8').read()
bootstrap_css = open('public/assets/css/other/bootstrap.min.css', encoding='utf-8').read()

# Take the Post Office row straight from the partial so the harness uses real markup
start = partial.index('{# === Post Office')
end = partial.index('{% include')
markup = partial[start:end]
markup = markup[markup.index('<div class="row'):]
markup = markup.replace('{{ govt_label }}', 'col-lg-2 col-md-3 col-12')
markup = markup.replace('{{ govt_input }}', 'col-lg-4 col-md-3 col-12')
markup = markup.replace('{{ prefix }}', 'present')
markup = re.sub(r'\{\{[^}]*\}\}', '', markup)

html = '''<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<title>Post office dropdown harness</title>
<style>__BOOTSTRAP__</style>
<style>__FORMSCSS__</style>
<style>
body { padding: 24px; background: #f6f7f9; }
#status { font-family: monospace; white-space: pre-wrap; margin-top: 16px; }
</style>
</head>
<body>
<h5>post office autocomplete harness (prefix = present)</h5>
__MARKUP__

<script>
// --- stub backend -------------------------------------------------------
window.__postOffices = [
    { en_name: 'Dhaka GPO', bn_name: 'ঢাকা জিপিও', post_code: '1000' },
    { en_name: 'Mohakhali', bn_name: 'মহাখালী', post_code: '1212' },
    { en_name: 'Mirzapur', bn_name: 'মিরজাপুর', post_code: '1490' },
    { en_name: 'Savar', bn_name: 'সাভার', post_code: '1340' }
];
window.__fetchLog = [];
window.fetch = function (url, opts) {
    window.__fetchLog.push(String(url));
    var body = { status: 'success', data: [] };
    if (String(url).indexOf('/api/post-offices') === 0 ||
        String(url).indexOf('/api/v2/geo/post-offices') === 0) {
        body = { status: 'success', data: window.__postOffices };
    }
    return Promise.resolve({ json: function () { return Promise.resolve(body); } });
};
</script>

<script>
__JS__
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        window.fetchPostOffices('TestUnion', '#present_postoffice_en', '#present_postoffice_bn');
    }, 50);
});
</script>
<div id="status">loading…</div>
</body>
</html>
'''

html = (html.replace('__MARKUP__', markup)
            .replace('__JS__', js)
            .replace('__BOOTSTRAP__', bootstrap_css)
            .replace('__FORMSCSS__', forms_css))
open('scripts/postoffice_dropdown_harness.html', 'w', encoding='utf-8', newline='\n').write(html)
print('harness bytes', len(html))
