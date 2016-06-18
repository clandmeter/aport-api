

JSONAPI implementations for aports packages.


Api is build around packages data and its relationships with other tables in database,
using, simple one-to-one relationships.
Currently, complex JOINS in database is being avoided and maybe used if needed.

Base URL
-----------
https://api.alpinelinux.org/

packages
----------
api uri: `<BaseURL>/packages/...`

Get packages list (paginated 50 items)
`curl https://api.alpinelinux.org/packages | jq . | less`


RELATIONSHIPS
==============
Following relationships exists, emanating from a base package<name>
* origin (original package to which the ''subpackage/named package'' belongs to)
* install_if 
* provides (what the package provides)
* depends (what the named package depends on)
* contents (contents of the package)
* flagged (if package is flagged, relationship to its data)


searching
-----------
* Searching packages by categories
api uri: `<BaseURL>/search/packages/category/<branch>:<repo>:<arch>/name/<pkgName>`
* Wildcard recogonized '_'

Eg. Basic search
`curl https://api.alpinelinux.org/search/packages/category/v3.4:all:x86/name/_bas_ | jq . | less`


categories
-----------
api uri: `<BaseURL>/categories`

Get categories available.
Categories are for Branch:Repo:Arch

Eg. Get available categories
`<BaseURL>/categories`


flagged packages
-----------------
See the current package that got flagged
`new=$(curl api.alpinelinux.org/aport-api/flagged/new | jq .data[].relationships.packages.links.self | sed -e 's/"//g'); curl $new | jq .data[].attributes.origin | uniq`


maintainers
------------
Get first page
`<BaseURL>/maintainer/names`
Get other pages
`<BaseURL>/maintainer/names/page/<num>`

