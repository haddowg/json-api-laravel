# Positions are written after fillUsing, and hidden members follow the linkage

An identified `MorphToMany` leaves its link rows to `fillUsing()`, so Laravel writes their positions in core's two passes once the hook has run, which needs the hook to create a new row with no position rather than one that collides with a held position under a unique index. On a positioned join, a replace keeps the members a scope hides, so they take the positions after the linkage's, the one placement that can't collide with an index the client chose.
