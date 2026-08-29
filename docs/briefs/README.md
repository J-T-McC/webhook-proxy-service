# Briefs

A brief is the default artifact for new work. One file, 100 lines or fewer,
covering what is being built and why, the decisions actually made, a short task
list, and the criteria for calling it done.

One senior-developer pass writes the brief and builds from it. There is no
separate PRD, design, plan, task plan or review document, and no approval gate:
the Project Owner sees the work at the pull request.

Use the pipeline instead — PRD, design, plan, tasks, review — only when the
change alters the data model, an external contract, or security posture, or is
expensive to reverse. That test is about consequence, not effort.

A brief is a working document, not a record. Edit it as the work moves; it does
not need an amendment history, and a decision that turns out wrong is corrected
in place rather than superseded.
