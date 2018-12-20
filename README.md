Spamkiller

Status: draft

This script is an extension for call routing to/from FRITZ!Box

Dependency: callmonitor (port 1012) is open - dial #96*5* to open it

The programm is listen to the callmonitor.
For an incoming call, it is checked whether the number is already known in the telephone book.
If not, it is checked at tellows if this unknown number has received a bad score (> 5) and more than 3 comments.
If this is the case, the number will be transferred to the corresponding phonebook for future rejections.


Author: Volker Püschel
