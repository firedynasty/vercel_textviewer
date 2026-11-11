-- Automator Quick Action: receives files or folders in Finder.
-- The installer fills in these paths for this checkout and Python installation.
on run {input, parameters}
	if (count of input) > 0 then
		set targetItem to item 1 of input as alias
		tell application "Finder"
			set selectedClass to class of item targetItem
			if selectedClass is folder or selectedClass is disk then
				set targetDir to POSIX path of targetItem
			else
				set targetDir to POSIX path of (container of item targetItem as alias)
			end if
		end tell
	else
		tell application "Finder"
			try
				set targetDir to POSIX path of (target of front window as alias)
			on error
				display dialog "Select a folder or open a Finder window first." buttons {"OK"} default button "OK"
				return input
			end try
		end tell
	end if
	try
		do shell script quoted form of "__PYTHON__" & " " & quoted form of "__FEED_LAUNCHER__" & " " & quoted form of targetDir
	on error errorMessage
		display dialog errorMessage buttons {"OK"} default button "OK" with title "Open Folder Feed"
	end try
	return input
end run
