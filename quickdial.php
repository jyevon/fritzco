<?php  
  header ("Content-Type:text/xml");
?>
<?xml version="1.0" encoding="utf-8" ?>
<!-- this variant displays numbers -->
<CiscoIPPhoneDirectory>
  <Title>Kurzwahlen</Title>

  <DirectoryEntry>
    <Name>Mobilteil Wohnzimmer</Name>
    <Telephone>**610</Telephone>
  </DirectoryEntry>
  <DirectoryEntry>
    <Name>Mobilteil 1. Etage</Name>
    <Telephone>**611</Telephone>
  </DirectoryEntry>
</CiscoIPPhoneDirectory>

<!-- if you have a old phone model and want more entries on the screen simultaneously, use this variant instead -->
<!--<CiscoIPPhoneMenu>
  <Title>Kurzwahlen</Title>
    <MenuItem>
      <Name>Mobilteil Wohnzimmer</Name>
      <URL>Dial:**610</URL>
    </MenuItem>
    <MenuItem>
      <Name>Mobilteil 1. Etage</Name>
      <URL>Dial:**611</URL>
    </MenuItem>
</CiscoIPPhoneMenu>-->