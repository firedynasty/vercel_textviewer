#!/bin/zsh
# folder_to_html.sh
# Takes a folder, generates index.html + content_data.js inside it.
#
# Usage:
#   folder_to_html.sh <folder_path>
#
# Logic:
#   - If <folder>/content_folder/ exists → use that as input, output to <folder>/
#   - Otherwise → use <folder> itself as input, output to <folder>/

SCRIPT_DIR="${0:A:h}"
PROCESS_PY="$SCRIPT_DIR/process_content.py"
INDEX_HTML="$SCRIPT_DIR/index.html"

folder="$1"

if [[ -z "$folder" ]]; then
    echo "Usage: folder_to_html.sh <folder_path>"
    exit 1
fi

folder="$(realpath "$folder")"

# Decide input vs output dirs
if [[ -d "$folder/content_folder" ]]; then
    input_dir="$folder/content_folder"
    output_dir="$folder"
else
    input_dir="$folder"
    output_dir="$folder"
fi

echo "▶ Input:  $input_dir"
echo "▶ Output: $output_dir"
echo ""

python3 "$PROCESS_PY" -i "$input_dir" -o "$output_dir/content_data.js"

if [[ $? -eq 0 ]]; then
    cp "$INDEX_HTML" "$output_dir/index.html"
    echo ""
    echo "✅ Done!"
    echo "   $output_dir/index.html"
    open "$output_dir/index.html"
else
    echo "❌ process_content.py failed"
    exit 1
fi
