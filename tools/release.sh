#!/usr/bin/env bash
# Builds a release archive and, with --publish, creates the GitHub release.
#
#   tools/release.sh                      build dist/TorrentMonitor-<version>.zip only
#   tools/release.sh --publish            build, tag v<version>, push the tag, create the release
#   tools/release.sh --publish notes.md   same, with release notes taken from a file
#
# The version is read from version.txt: change it there (and merge it) first.
# TorrentMonitor installations find new versions through GitHub Releases,
# so publishing a release is what makes an update visible to users.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

publish=0
notes=""
for arg in "$@"; do
    case "$arg" in
        --publish) publish=1 ;;
        -h|--help) sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) notes="$arg" ;;
    esac
done

fail() { echo "error: $*" >&2; exit 1; }

version=$(php -r '$v = json_decode(file_get_contents("version.txt")); echo $v->system ?? "";')
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || fail "version.txt has no valid version: '$version'"
tag="v$version"
archive="dist/TorrentMonitor-$version.zip"

[[ -z "$(git status --porcelain)" ]] || fail "working tree is not clean"
[[ -z "$notes" || -f "$notes" ]] || fail "notes file not found: $notes"

# every PHP file must at least parse
while IFS= read -r file; do
    php -l "$file" > /dev/null || fail "syntax error in $file"
done < <(git ls-files '*.php')

mkdir -p dist
rm -f "$archive"
git archive --format=zip --prefix="TorrentMonitor-$version/" -o "$archive" HEAD
echo "built $archive ($(du -h "$archive" | cut -f1))"

if [[ $publish -eq 0 ]]; then
    echo "not published; run with --publish to create release $tag"
    exit 0
fi

branch=$(git rev-parse --abbrev-ref HEAD)
[[ "$branch" == "master" ]] || fail "releases are published from master, current branch is $branch"
git fetch --quiet origin master
[[ "$(git rev-parse HEAD)" == "$(git rev-parse origin/master)" ]] || fail "local master differs from origin/master"
git rev-parse -q --verify "refs/tags/$tag" > /dev/null && fail "tag $tag already exists"

# always release into the repository behind "origin", never into the upstream of a fork
repo=$(git remote get-url origin | sed -E 's#^(git@github\.com:|https://github\.com/)##; s#\.git$##')
[[ "$repo" =~ ^[^/]+/[^/]+$ ]] || fail "cannot determine GitHub repository from origin: $repo"

git tag -a "$tag" -m "version $version"
git push origin "$tag"
if [[ -n "$notes" ]]; then
    gh release create "$tag" "$archive" --repo "$repo" --title "$version" --notes-file "$notes"
else
    gh release create "$tag" "$archive" --repo "$repo" --title "$version" --generate-notes
fi
echo "published https://github.com/$repo/releases/tag/$tag"
