#!/bin/zsh

# Install the three local AskMyDocs services in the current user's launchd
# domain. Templates deliberately contain no environment secrets: Laravel reads
# the existing application .env from the configured project root.
set -euo pipefail

script_dir="${0:A:h}"
project_root="${script_dir:h:h}"
herd_bin="${HERD_BIN:-/Users/marco/Library/Application Support/Herd/bin/herd}"
herd_bin_dir="${herd_bin:h}"
launch_agents_dir="${HOME}/Library/LaunchAgents"
uid="$(id -u)"

if [[ ! -x "${herd_bin}" ]]; then
    print -u2 "Herd executable not found: ${herd_bin}"
    exit 1
fi

mkdir -p "${launch_agents_dir}"

for template in "${script_dir}"/*.plist.template; do
    label="${${template:t}%.plist.template}"
    destination="${launch_agents_dir}/${label}.plist"

    sed \
        -e "s|__ASKMYDOCS_ROOT__|${project_root}|g" \
        -e "s|__HERD_BIN__|${herd_bin}|g" \
        -e "s|__HERD_BIN_DIR__|${herd_bin_dir}|g" \
        "${template}" > "${destination}"
    plutil -lint "${destination}"

    launchctl bootout "gui/${uid}/${label}" 2>/dev/null || true
    launchctl bootstrap "gui/${uid}" "${destination}"
    launchctl enable "gui/${uid}/${label}"
    print "Installed and started ${label}"
done
