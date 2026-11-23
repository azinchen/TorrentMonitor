#!/usr/bin/env bash
# Builds a release archive and, with --publish, creates the GitHub release.
#
#   tools/release.sh                      build dist/TorrentMonitor-<version>.zip only
#   tools/release.sh --publish            build and create the release (GitHub creates the tag v<version>)
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
# a connection to GitHub over SSH sometimes stalls: give up after a minute and try again
fetched=0
for attempt in 1 2 3; do
    if timeout 60 git fetch --quiet origin master; then fetched=1; break; fi
    echo "fetch attempt $attempt failed, retrying"
done
[[ $fetched -eq 1 ]] || fail "cannot fetch origin/master"
head=$(git rev-parse HEAD)
[[ "$head" == "$(git rev-parse origin/master)" ]] || fail "local master differs from origin/master"

# always release into the repository behind "origin", never into the upstream of a fork
repo=$(git remote get-url origin | sed -E 's#^(git@github\.com:|https://github\.com/)##; s#\.git$##')
[[ "$repo" =~ ^[^/]+/[^/]+$ ]] || fail "cannot determine GitHub repository from origin: $repo"

# The tag is created by GitHub together with the release, on the commit that was checked above:
# nothing is pushed from here. A run that stopped half-way can simply be repeated.
gh release view "$tag" --repo "$repo" > /dev/null 2>&1 && fail "release $tag already exists"
if git rev-parse -q --verify "refs/tags/$tag^{commit}" > /dev/null; then
    [[ "$(git rev-parse "refs/tags/$tag^{commit}")" == "$head" ]] || fail "local tag $tag points to another commit"
fi
target=(--target "$head")
if remote=$(gh api "repos/$repo/commits/$tag" --jq .sha 2> /dev/null); then
    [[ "$remote" == "$head" ]] || fail "tag $tag already exists on GitHub and points to another commit"
    target=()
fi

# a version with a suffix (4.0.0-beta1) is a pre-release: GitHub does not report it as the latest
# release, so installed copies are not offered it as an update
flags=()
[[ "$version" == *-* ]] && flags+=(--prerelease)
if [[ -n "$notes" ]]; then
    flags+=(--notes-file "$notes")
else
    flags+=(--generate-notes)
fi
gh release create "$tag" "$archive" --repo "$repo" --title "$version" "${flags[@]}" ${target[@]+"${target[@]}"}

# bring the tag here; not essential, so a network hiccup does not fail a release that is already out
timeout 60 git fetch --quiet origin "refs/tags/$tag:refs/tags/$tag" 2> /dev/null || echo "note: fetch the tag later with: git fetch origin tag $tag"
echo "published https://github.com/$repo/releases/tag/$tag"
