#
# Sphinx configuration file
#
# See CONTRIBUTING.md for instructions on how to build the documentation.
#

import sphinx_rtd_theme
from sphinx.highlighting import lexers
from pygments.lexers.web import PhpLexer

project = u'JMSPaymentPaypalBundle'

extensions = [
    'sensio.sphinx.configurationblock',
]

master_doc = 'index'

html_show_copyright = False
html_theme = 'sphinx_rtd_theme'
html_theme_path = [sphinx_rtd_theme.get_html_theme_path()]

# Allow omiting ``<?php`` and still have syntax highlighting
lexers['php'] = PhpLexer(startinline=True)
lexers['php-annotations'] = PhpLexer(startinline=True)
