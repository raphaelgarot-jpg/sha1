#!/usr/bin/python2.7

import socket
import sys

UDP_IP = "192.168.0.26"
UDP_PORT = 49880

#################### Basic values ########################
lo = "4,"
hi = "12,"
seqLo = "4,12,4,12," # lo + hi + lo + hi
seqFl = "4,12,12,4," # lo + hi + hi + lo
h = "4,12,12,4," # = seqFl
l = "4,12,4,12," # = seqLo
ToggleOn = "4,12,12,4,4,12,12,4," # = h + h
ToggleOff = "4,12,12,4,4,12,4,12," # = h + l
additional = "4,12,4,12,4,12,12,4," # = l + h
headITGW = "0,0,6,11125,89,26,0,"
tx433version = "1,"
sPeedITGW = "125,"
tailITGW = tx433version + sPeedITGW + "0"

#################### Master ########################
if sys.argv[1] == "A":
	Master = l + l + l + l
elif sys.argv[1] == "B":
	Master = h + l + l + l
elif sys.argv[1] == "C":
	Master = l + h + l + l
elif sys.argv[1] == "D":
	Master = h + h + l + l
elif sys.argv[1] == "E":
	Master = l + l + h + l
elif sys.argv[1] == "F":
	Master = h + l + h + l
elif sys.argv[1] == "G":
	Master = l + h + h + l
elif sys.argv[1] == "H":
	Master = h + h + h + l
elif sys.argv[1] == "I":
	Master = l + l + l + h
elif sys.argv[1] == "J":
	Master = h + l + l + h
elif sys.argv[1] == "K":
	Master = l + h + l + h
elif sys.argv[1] == "L":
	Master = h + h + l + h
elif sys.argv[1] == "M":
	Master = l + l + h + h
elif sys.argv[1] == "N":
	Master = h + l + h + h
elif sys.argv[1] == "O":
	Master = l + h + h + h
elif sys.argv[1] == "P":
	Master = h + h + h + h

#################### Slave ########################
if sys.argv[2] == "1":
	Slave = l + l + l + l
elif sys.argv[2] == "2":
	Slave = h + l + l + l
elif sys.argv[2] == "3":
	Slave = l + h + l + l
elif sys.argv[2] == "4":
	Slave = h + h + l + l
elif sys.argv[2] == "5":
	Slave = l + l + h + l
elif sys.argv[2] == "6":
	Slave = h + l + h + l
elif sys.argv[2] == "7":
	Slave = l + h + h + l
elif sys.argv[2] == "8":
	Slave = h + h + h + l
elif sys.argv[2] == "9":
	Slave = l + l + l + h
elif sys.argv[2] == "10":
	Slave = h + l + l + h
elif sys.argv[2] == "11":
	Slave = l + h + l + h
elif sys.argv[2] == "12":
	Slave = h + h + l + h
elif sys.argv[2] == "13":
	Slave = l + l + h + h
elif sys.argv[2] == "14":
	Slave = h + l + h + h
elif sys.argv[2] == "15":
	Slave = l + h + h + h
elif sys.argv[2] == "16":
	Slave = h + h + h + h

#################### Toggle ########################
if sys.argv[3] == "on":
	Toggle = ToggleOn
elif sys.argv[3] == "off":
	Toggle = ToggleOff

MESSAGE = headITGW + Master + Slave + additional + Toggle + tailITGW

print ("UDP target IP:", UDP_IP)
print ("UDP target port:", UDP_PORT)
print ("message:", MESSAGE)

sock = socket.socket(socket.AF_INET, # Internet
socket.SOCK_DGRAM) # UDP
sock.sendto(MESSAGE, (UDP_IP, UDP_PORT))
