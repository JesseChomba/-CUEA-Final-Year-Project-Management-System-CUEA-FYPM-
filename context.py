import os
import json
from pathlib import Path

def generate_context(root_dir, output_file="context.json", ignore_dirs=None):
    if ignore_dirs is None:
        ignore_dirs = {'.git', '__pycache__', 'node_modules', '.venv', 'env'}
    
    root = Path(root_dir)
    file_map = {}

    def build_tree(directory, prefix=""):
        """Prints the tree structure to console."""
        entries = sorted([e for e in directory.iterdir() if e.name not in ignore_dirs], 
                         key=lambda e: (e.is_file(), e.name))
        
        for i, entry in enumerate(entries):
            connector = "└── " if i == len(entries) - 1 else "├── "
            print(f"{prefix}{connector}{entry.name}")
            
            if entry.is_dir():
                build_tree(entry, prefix + ("    " if i == len(entries) - 1 else "│   "))
            else:
                # Read file content for the JSON output
                try:
                    with open(entry, 'r', encoding='utf-8') as f:
                        file_map[str(entry.relative_to(root))] = f.read()
                except Exception as e:
                    file_map[str(entry.relative_to(root))] = f"Error reading file: {e}"

    print(f"Directory Structure for: {root.resolve()}\n")
    build_tree(root)

    # Save to JSON
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(file_map, f, indent=4)
    
    print(f"\nSuccessfully generated {output_file}")

# Usage
if __name__ == "__main__":
    # Change '.' to your specific path if needed
    generate_context('.')