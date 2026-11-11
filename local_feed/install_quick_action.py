#!/usr/bin/env python3
"""Install the Finder action, or generate it in a chosen directory for review."""
import argparse
from pathlib import Path
import plistlib
import subprocess
import sys
import uuid

HERE = Path(__file__).resolve().parent
NAME = 'Open Folder Feed'


def applescript_string(value):
    return str(value).replace('\\', '\\\\').replace('"', '\\"')


def install(services):
    contents = services / f'{NAME}.workflow' / 'Contents'
    if contents.exists():
        raise SystemExit(f'Quick Action already exists: {contents.parent}. Rename it before reinstalling.')
    script = (HERE / f'{NAME}.applescript').read_text()
    script = script.replace('__PYTHON__', applescript_string(sys.executable))
    script = script.replace('__FEED_LAUNCHER__', applescript_string(HERE / 'server.py'))
    action = {
        'AMAccepts': {'Container': 'List', 'Optional': True, 'Types': ['com.apple.applescript.object']},
        'AMProvides': {'Container': 'List', 'Types': ['com.apple.applescript.object']},
        'AMActionIdentifier': 'com.apple.Automator.RunScript',
        'ActionBundlePath': '/System/Library/Automator/Run AppleScript.action',
        'ActionName': 'Run AppleScript',
        'ActionParameters': {'source': script},
        'AMParameterProperties': {'source': {}},
        'BundleIdentifier': 'com.apple.Automator.RunScript',
        'CFBundleVersion': '1.0.2',
        'AMActionVersion': '1.0.2',
        'AMApplication': ['Automator'],
        'CanShowSelectedItemsWhenRun': False,
        'CanShowWhenRun': True,
        'Category': ['AMCategoryUtilities'],
        'UnlocalizedApplications': ['Automator'],
        'Class Name': 'RunScriptAction',
        'InputUUID': str(uuid.uuid4()).upper(),
        'OutputUUID': str(uuid.uuid4()).upper(),
        'UUID': str(uuid.uuid4()).upper(),
        'arguments': {}, 'isViewVisible': 1,
    }
    workflow = {
        'AMDocumentVersion': '2', 'AMApplicationVersion': '2.10',
        'actions': [{'action': action, 'isViewVisible': 1}], 'connectors': {},
        'workflowMetaData': {
            'applicationBundleID': 'com.apple.finder',
            'applicationPath': '/System/Library/CoreServices/Finder.app',
            'inputTypeIdentifier': 'com.apple.Automator.fileSystemObject',
            'outputTypeIdentifier': 'com.apple.Automator.nothing',
            'processesInput': False,
            'useAutomaticInputType': False,
            'serviceInputTypeIdentifier': 'com.apple.Automator.fileSystemObject',
            'serviceOutputTypeIdentifier': 'com.apple.Automator.nothing',
            'serviceProcessesInput': False,
            'serviceApplicationBundleID': 'com.apple.finder',
            'serviceApplicationPath': '/System/Library/CoreServices/Finder.app',
            'workflowTypeIdentifier': 'com.apple.Automator.servicesMenu',
        },
    }
    info = {'NSServices': [{
        'NSMenuItem': {'default': NAME}, 'NSMessage': 'runWorkflowAsService',
        'NSRequiredContext': {'NSApplicationIdentifier': 'com.apple.finder'},
        'NSSendFileTypes': ['public.item'],
    }]}
    contents.mkdir(parents=True)
    for filename, value in [('document.wflow', workflow), ('Info.plist', info)]:
        with (contents / filename).open('wb') as output:
            plistlib.dump(value, output)
    # A filled-in standalone script is useful when replacing an existing action.
    (contents / 'action.applescript').write_text(script)
    print(f'Created {contents.parent}')
    if services.resolve() == (Path.home() / 'Library' / 'Services').resolve():
        subprocess.run(['/System/Library/CoreServices/pbs', '-update'], check=False)
        print('Finder → right-click a file or folder → Quick Actions → Open Folder Feed')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir', type=Path, default=Path.home() / 'Library' / 'Services')
    install(parser.parse_args().output_dir.expanduser())
