import os
import re

def process_twig_file(file_path):
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    # Use regular expression to find and replace the specified text
    modified_content = re.sub(r'templates/Aesthetiful/Main/', '', content)

    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(modified_content)

def find_and_replace(directory):
    for root, dirs, files in os.walk(directory):
        for file in files:
            if file.endswith('.twig'):
                file_path = os.path.join(root, file)
                process_twig_file(file_path)

if __name__ == "__main__":
    find_and_replace("C:/Users/patbr/Downloads/finoob/sitetest1finobenet/resources/views/v1/")
    print("Replacement complete.")

# this was used to replace template text thing