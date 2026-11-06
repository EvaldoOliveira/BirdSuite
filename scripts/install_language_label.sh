#!/usr/bin/env bash
################################################################################
# scripts/install_language_label.sh - THE LABEL FILE OF THE COMMON-NAME LANGUAGE
#
# PURPOSE:
#   Installs the label file of the chosen common-name language into the model folder (utils/maintainer.py),
#   run at the install and when the language changes.
#
# DEPENDENCIES:
#   - scripts/paths.sh
#   - scripts/select_species_list.py
#
# USAGE:
#   bash scripts/install_language_label.sh
#   Referenced by scripts/backup_data.sh, scripts/install_birdnet.sh, scripts/model_page.php,
#   scripts/setup_wizard.php
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
cd "$BS_SCRIPTS"

# The label file is built from the model's own class list. Models fetched on first use (BirdNET+ V3)
# only have it after their download, which used to happen at the first analysis start — after the
# installer had already tried (and silently failed) to write model/labels.txt (US-51e acceptance
# install, 2026-10-07). Fetch the model first, with the station's Python environment.
venv_python=$BS_PYTHON
if [ -x "$venv_python" ]; then
	"$venv_python" -c 'from utils.models import get_model; get_model()' > /dev/null 2>&1 \
		|| echo "Could not prepare the model ${MODEL} (no internet?) - the label file may be incomplete"
fi

python3 -c 'from utils.helpers import set_label_file; set_label_file()'
ret=$?
# not fatal: without internet the model (and so its label file) arrives at the first analysis start,
# which writes model/labels.txt itself when it is missing
[ $ret -ne 0 ] && echo "The label file model/labels.txt could not be written for ${MODEL} / ${DATABASE_LANG} - it will be written when the analysis first starts"

# the species list chosen at install (SPECIES_LIST, e.g. a Brazilian state) is built and activated
python3 ./select_species_list.py || echo "The species list could not be prepared - it is completed when the analysis first starts"

cd - > /dev/null
exit 0
