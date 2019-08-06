/*
** Zabbix
** Copyright (C) 2001-2019 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

package main

import (
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"
	"time"
	"zabbix/internal/agent"
	"zabbix/internal/agent/scheduler"
	"zabbix/internal/agent/serverconnector"
	"zabbix/internal/agent/serverlistener"
	"zabbix/internal/monitor"
	"zabbix/pkg/conf"
	"zabbix/pkg/log"
	"zabbix/pkg/version"
	"zabbix/pkg/zbxlib"
	_ "zabbix/plugins"
)

func configDefault(taskManager scheduler.Scheduler, o *agent.AgentOptions) error {
	var err error
	const hostNameLen = 128

	if len(o.Hostname) == 0 {
		var hostnameItem string

		if len(o.HostnameItem) == 0 {
			hostnameItem = "system.hostname"
		} else {
			hostnameItem = o.HostnameItem
		}

		o.Hostname, err = taskManager.PerformTask(hostnameItem, time.Second*time.Duration(o.Timeout))
		if err != nil {
			if len(o.HostnameItem) == 0 {
				return fmt.Errorf("cannot get system hostname using \"%s\" item as default for \"HostnameItem\" configuration parameter: %s", hostnameItem, err.Error())
			}

			return fmt.Errorf("cannot get system hostname using \"%s\" item specified by \"HostnameItem\" configuration parameter: %s", hostnameItem, err.Error())
		}

		if len(o.Hostname) == 0 {
			return fmt.Errorf("cannot get system hostname using \"%s\" item specified by \"HostnameItem\" configuration parameter: value is empty", hostnameItem)
		}

		if len(o.Hostname) > hostNameLen {
			o.Hostname = o.Hostname[:hostNameLen]
			log.Warningf("the returned value of \"%s\" item specified by \"HostnameItem\" configuration parameter is too long, using first %d characters", hostnameItem, hostNameLen)
		}

		if err = agent.CheckHostname(o.Hostname); nil != err {
			return fmt.Errorf("cannot get system hostname using \"%s\" item specified by \"HostnameItem\" configuration parameter: %s", hostnameItem, err.Error())
		}
	} else {
		if len(o.HostnameItem) != 0 {
			log.Warningf("both \"Hostname\" and \"HostnameItem\" configuration parameter defined, using \"Hostname\"")
		}

		if len(o.Hostname) > hostNameLen {
			return fmt.Errorf("invalid \"Hostname\" configuration parameter: configuration parameter cannot be longer than %d characters", hostNameLen)
		}
		if err = agent.CheckHostname(o.Hostname); nil != err {
			return fmt.Errorf("invalid \"Hostname\" configuration parameter: %s", err.Error())
		}
	}

	return nil
}

func run() {
	sigs := make(chan os.Signal, 1)
	signal.Notify(sigs, syscall.SIGINT, syscall.SIGTERM, syscall.SIGUSR1)

	for {
		sig := <-sigs
		switch sig {
		case syscall.SIGINT, syscall.SIGTERM:
			return
		case syscall.SIGUSR1:
			log.Debugf("user signal received")
			return
		}
	}
}

func main() {
	var confFlag string
	const (
		confDefault     = "agent.conf"
		confDescription = "Path to the configuration file"
	)
	flag.StringVar(&confFlag, "config", confDefault, confDescription)
	flag.StringVar(&confFlag, "c", confDefault, confDescription+" (shorhand)")

	var foregroundFlag bool
	const (
		foregroundDefault     = true
		foregroundDescription = "Run Zabbix agent in foreground"
	)
	flag.BoolVar(&foregroundFlag, "foreground", foregroundDefault, foregroundDescription)
	flag.BoolVar(&foregroundFlag, "f", foregroundDefault, foregroundDescription+" (shorhand)")

	var testFlag string
	const (
		testDefault     = ""
		testDescription = "Test specified item and exit"
	)
	flag.StringVar(&testFlag, "test", testDefault, testDescription)
	flag.StringVar(&testFlag, "t", testDefault, testDescription+" (shorhand)")

	var printFlag bool
	const (
		printDefault     = false
		printDescription = "Print known items and exit"
	)
	flag.BoolVar(&printFlag, "print", printDefault, printDescription)
	flag.BoolVar(&printFlag, "p", printDefault, printDescription+" (shorhand)")

	var versionFlag bool
	const (
		versionDefault     = false
		versionDescription = "Print programm version and exit"
	)
	flag.BoolVar(&versionFlag, "version", versionDefault, versionDescription)
	flag.BoolVar(&versionFlag, "v", versionDefault, versionDescription+" (shorhand)")

	flag.Parse()

	var argConfig, argTest, argPrint, argVersion bool

	// Need to manually check if the flag was specified, as default flag package
	// does not offer automatic detection. Consider using third party package.
	flag.Visit(func(f *flag.Flag) {
		switch f.Name {
		case "t", "test":
			argTest = true
		case "p", "print":
			argPrint = true
		case "c", "config":
			argConfig = true
		case "v", "version":
			argVersion = true
		}
	})

	if argVersion {
		version.Display()
		os.Exit(0)
	}

	if argConfig {
		if err := conf.Load(confFlag, &agent.Options); err != nil {
			fmt.Fprintf(os.Stderr, "%s\n", err.Error())
			os.Exit(1)
		}
	}

	if argTest || argPrint {
		if err := log.Open(log.Console, log.Warning, ""); err != nil {
			fmt.Fprintf(os.Stderr, "Cannot initialize logger: %s\n", err.Error())
			os.Exit(1)
		}

		if argTest {
			if err := agent.CheckMetric(testFlag); err != nil {
				os.Exit(1)
			}
		} else {
			agent.CheckMetrics()
		}

		os.Exit(0)
	}

	var logType, logLevel int
	switch agent.Options.LogType {
	case "console":
		logType = log.Console
	case "file":
		logType = log.File
	}
	switch agent.Options.DebugLevel {
	case 0:
		logLevel = log.Info
	case 1:
		logLevel = log.Crit
	case 2:
		logLevel = log.Err
	case 3:
		logLevel = log.Warning
	case 4:
		logLevel = log.Debug
	case 5:
		logLevel = log.Trace
	}

	if err := log.Open(logType, logLevel, agent.Options.LogFile); err != nil {
		fmt.Fprintf(os.Stderr, "Cannot initialize logger: %s\n", err.Error())
		os.Exit(1)
	}

	zbxlib.SetLogLevel(logLevel)

	addresses, err := serverconnector.ParseServerActive()
	if err != nil {
		log.Critf("%s", err)
		os.Exit(1)
	}

	greeting := fmt.Sprintf("Starting Zabbix Agent [%s]. (version placeholder)", agent.Options.Hostname)
	log.Infof(greeting)

	if foregroundFlag {
		if agent.Options.LogType != "console" {
			fmt.Println(greeting)
		}
		fmt.Println("Press Ctrl+C to exit.")
	}

	log.Infof("using configuration file: %s", confFlag)

	taskManager := scheduler.NewManager()
	listener := serverlistener.New(taskManager)

	taskManager.Start()

	var serverConnectors []*serverconnector.Connector

	err = configDefault(taskManager, &agent.Options)

	if err == nil {
		serverConnectors = make([]*serverconnector.Connector, len(addresses))

		for i := 0; i < len(serverConnectors); i++ {
			serverConnectors[i] = serverconnector.New(taskManager, addresses[i])
			serverConnectors[i].Start()
		}

		err = listener.Start()
	}

	if err == nil {
		run()
	} else {
		log.Errf("cannot start agent: %s", err.Error())
	}

	listener.Stop()

	for i := 0; i < len(serverConnectors); i++ {
		serverConnectors[i].Stop()
	}

	taskManager.Stop()
	monitor.Wait()

	farewell := fmt.Sprintf("Zabbix Agent stopped. (version placeholder)")
	log.Infof(farewell)

	if foregroundFlag {
		if agent.Options.LogType != "console" {
			fmt.Println(farewell)
		}
		fmt.Println("Press Ctrl+C to exit.")
	}
}
