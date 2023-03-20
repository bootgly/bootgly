# WSL 2 Performance - Tip

If you want to benchmark the Bootgly CLI Server, you need to uninstall and install "Windows Subsystem For Linux" to reset the performance of WSL 2 Kernel.

Go to Windows -> Config. -> Applications -> Installed Applications and search for "Windows Subsystem For Linux". Uninstall it and reboot your system to reinstall! No data will be removed and your distro will be intact.

I don't know what really happens behind the scenes, but WSL2 loses performance over time with JIT enabled and the marks in the Benchmark decrease to about 10% of permanent loss.

I discovered this after fully resetting my WSL2.

Note that it is not necessary to unregister or remove your distro data. Your files and distro changes should and will remain intact even if you uninstall the "Windows Subsystem For Linux" app only.

After uninstalling, just restart the computer, go to the PowerShell terminal and type this command:

`wsl --update`

After that your WSL2 will have the maximum possible performance in the benchmark tests.
