#!/bin/bash
# Process Content Folder - Source this file in your ~/.zshrc or ~/.bashrc
# Add to your shell config: source /path/to/processcontent.sh
# Then run: source ~/.zshrc

PROCESS_CONTENT_PY="/Users/stanleytan/Documents/25-technical/01-github/vercel_textviewer/staticversionhtml/process_content.py"
INDEX_HTML_SOURCE="/Users/stanleytan/Documents/25-technical/01-github/vercel_textviewer/staticversionhtml/index.html"

processcontent() {
  if [[ "$1" == "--help" ]] || [[ "$1" == "-h" ]]; then
    echo "Usage: processcontent [folder] [options]"
    echo ""
    echo "This command will:"
    echo "  1. Generate content_data.js from content_folder"
    echo "  2. Copy index.html to the target folder"
    echo ""
    echo "Examples:"
    echo "  processcontent                     # process ./content_folder → ./content_data.js + index.html"
    echo "  processcontent ~/Downloads         # process ~/Downloads/content_folder"
    echo "  processcontent -i ./my_folder      # specify custom input folder"
    echo "  processcontent -o data.js          # specify custom output file"
    echo ""
    echo "Options:"
    echo "  -i DIR      Input directory (default: ./content_folder)"
    echo "  -o FILE     Output file (default: ./content_data.js)"
    return 0
  fi

  local folder="${1:-.}"

  # If first arg doesn't start with -, treat it as folder location
  if [[ ! "$1" =~ ^- ]] && [[ -n "$1" ]]; then
    folder="$1"
    shift
  fi

  # Convert to absolute path
  folder="$(realpath "$folder")"

  # Check if content_folder exists in the specified directory
  local contents_path="$folder/content_folder"
  if [[ ! -d "$contents_path" ]]; then
    echo "Error: content_folder not found in $folder"
    echo ""
    echo "Looking for: $contents_path"
    return 1
  fi

  echo "Processing: $contents_path"
  echo ""

  # Change to the folder and run the script with -i content_folder
  (cd "$folder" && python "$PROCESS_CONTENT_PY" -i ./content_folder "$@")

  if [[ $? -eq 0 ]]; then
    echo ""
    echo "✓ Generated content_data.js"

    # Copy index.html to the target folder
    if [[ -f "$INDEX_HTML_SOURCE" ]]; then
      cp "$INDEX_HTML_SOURCE" "$folder/index.html"
      echo "✓ Copied index.html"
    else
      echo "⚠ Warning: index.html not found at $INDEX_HTML_SOURCE"
    fi
  fi
}
