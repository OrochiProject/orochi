time LD_PRELOAD="/usr/lib/libprofiler.so /usr/lib/libtcmalloc.so" CPUPROFILE_FREQUENCY=10000 CPUPROFILE=/tmp/prof.out ./hintprocess /tmp/trace_seq.log /tmp/sess.log /tmp/apc.log /tmp/sql.log /tmp/maxop.log /tmp/opmap.mem 