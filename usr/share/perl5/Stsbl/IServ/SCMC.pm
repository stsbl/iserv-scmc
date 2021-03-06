# SCMC Library

package Stsbl::IServ::SCMC;
use warnings;
use strict;
use utf8;
use Encode qw(decode encode);
use Fcntl ":flock";
use IServ::Act;
use IServ::DB;
use IServ::Valid;
use Stsbl::IServ::IO;
use Stsbl::IServ::Log;
use Stsbl::IServ::Security;

BEGIN
{
  use Exporter;
  our @ISA = qw(Exporter);
  our @EXPORT = qw(crypt_check getusrnam legacy_crypt);
}

my $fn_master_passwd = "/etc/stsbl/scmcmasterpasswd";
my $fn_user_passwd = "/etc/stsbl/scmcpasswd";

my $m_legacy_hash = qr/^\$2y/;

sub crypt_check($$)
{
  my ($plain, $salt) = @_;
  return if not defined $plain or $plain eq "";
  return if not defined $salt or $salt eq "";
  my $crypt = crypt $plain, $salt;
  defined $crypt and $crypt eq $salt;
}

sub getusrnam($)
{
  my ($act) = @_;
  open my $fh, "<", $fn_user_passwd;
  while (<$fh>)
  {
    chomp;
    my @f = split /:/;
    my $uid = $f[0];
    # we need to store the uid instead of the account name in scmcpasswd, 
    # as we cannot handle account name updates :/ .
    my $name = getpwuid $uid;
    $f[0] = $name;
    return @f if $name eq $act;
  }
}

sub getmasternam
{
  open my $fh, "<", $fn_master_passwd;
  while (<$fh>)
  {
    chomp;
    return split /:/;
  }
}

sub legacy_crypt($$)
{
  my ($pwd, $salt) = @_;
  my $crypt;
  # calculate legacy hash
  eval
  {
    local $ENV{SCMC_SESSIONPW} = $pwd;
    local $ENV{SCMC_SESSIONSALT} = $salt;
    $crypt = qx(/usr/lib/iserv/scmc_php_hash);
    chomp $crypt;
  };
  
  die "calculating of php hash failed: $@" if $@;
  
  return $crypt if defined $crypt;
}

sub MasterPasswdEnc($)
{
  my $pw = shift;
  open my $fh, ">", $fn_master_passwd or die "Couldn't open file $fn_master_passwd: $!\n";
  flock $fh, LOCK_EX or die "Couldn't lock file $fn_master_passwd: $!\n";
  print $fh "$pw\n";
  close $fh or die "Couldn't write file $fn_master_passwd: $!\n";
}

sub MasterPasswd($)
{
  my $pw = shift;
  $pw = IServ::Act::crypt_auto $pw;
  MasterPasswdEnc $pw;
}

sub SetMasterPasswd($;$)
{
  my ($pw, $oldpw) = @_;
  my @masternam = getmasternam;
  
  if (-s $fn_master_passwd > 0 and not defined $oldpw)
  {
    my $text = "Masterpasswortaktualisierung fehlgeschlagen: Altes Passwort falsch";
    my %row;
    $row{module} = "School Certificate Manager Connector";
    Stsbl::IServ::Log::log_store $text, %row;
    error $text;
  }

  if (-s $fn_master_passwd == 0)
  {
    # hack to jump over the other conditions
  } elsif ($masternam[0] =~ $m_legacy_hash)
  {
    my $legacy_crypt = legacy_crypt $oldpw, $masternam[1];
  
    if (not $legacy_crypt eq $masternam[0])
    {
      my $text = "Masterpasswortaktualisierung fehlgeschlagen: Altes Passwort falsch";
      my %row;
      $row{module} = "School Certificate Manager Connector";
      Stsbl::IServ::Log::log_store $text, %row;
      error $text;
    }
  } elsif (not crypt_check $oldpw, $masternam[0])
  {
    my $text = "Masterpasswortaktualisierung fehlgeschlagen: Altes Passwort falsch";
    my %row;
    $row{module} = "School Certificate Manager Connector";
    Stsbl::IServ::Log::log_store $text, %row;
    error $text;
  }

  eval
  {
    MasterPasswd $pw;
  };
  error "Setzen des Masterpasswortes fehlgeschlagen: $@" if $@;
  Stsbl::IServ::Log::write_for_module "Masterpasswort erfolgreich aktualisiert",
    "School Certificate Manager Connector";
}

sub UserPasswdEnc($;$)
{
  my ($act, $pw) = @_;
  my %out;

  open my $fh, "<", $fn_user_passwd;
  while (<$fh>)
  {
    my @line = split /:/;
    if (my ($name, undef, $uid) = getpwuid $line[0])
    {
      # skip account which should get the new password
      unless ($name eq $act)
      {
        # we need to have uid in passwd file, as we cannot handle account name updates :/ .
        $line[0] = $uid;
        $out{$line[0]} = \@line;
      }
    } else
    {
      #print STDERR "user $line[0] seems to does not exists!\n".
      #  "password of user $line[0] will not transferred to new passwd file!\n";
    }
  }
  close $fh;

  open $fh, ">", $fn_user_passwd;
  flock $fh, LOCK_EX or die "Couldn't lock file $fn_user_passwd: $!\n";
  foreach my $index (keys %out)
  {
    my $line = join ":", @{ $out{$index} };
    print $fh $line;
  }

  if (defined $pw)
  {
    my (undef, undef, $uid) = getpwnam $act;
    # add new password at bottom
    print $fh "$uid:$pw\n";
  }
  close $fh;
}

sub UserPasswd($;$)
{
  my ($act, $pw) = @_;
  $pw = IServ::Act::crypt_auto $pw if defined $pw;
  UserPasswdEnc $act, $pw;
}

sub SetUserPasswd($$)
{
  my ($act, $pw) = @_;
  eval
  {
    $act = IServ::Valid::User $act;
    $pw = IServ::Valid::Passwd $pw;
    UserPasswd $act, $pw;
  };
  error "Setzen des Benutzerpasswortes fehlgeschlagen: $@" if $@;
 
  my $fullname = decode "UTF-8", encode "UTF-8",
      IServ::DB::SelectVal "SELECT user_join_name(firstname, lastname) ".
      "FROM users_name WHERE act = ?", $act;

  # update state
  if (IServ::DB::Do "SELECT 1 FROM scmc_userpasswords WHERE act = ?", $act)
  {
    IServ::DB::Do "UPDATE scmc_userpasswords SET password = true WHERE act = ?", $act;
  } else
  {
    IServ::DB::Do "INSERT INTO scmc_userpasswords (act, password) VALUES (?, true)", $act;
  }

  my $text = "Benutzerpasswort von $fullname gesetzt";
  my $out = encode "UTF-8", $text;
  print "$out.\n";
  my %row;
  $row{module} = "School Certificate Manager Connector";
  Stsbl::IServ::Log::log_store $out, %row;
}

sub DeleteUserPasswd($)
{
  my $act = shift;
  eval
  {
    $act = IServ::Valid::User $act;
    UserPasswd $act;
  };
  error "Löschen des Benutzerpasswortes fehlgeschlagen: $@" if $@;
 
  my $fullname = decode "UTF-8", encode "UTF-8",
      IServ::DB::SelectVal "SELECT user_join_name(firstname, lastname) ".
      "FROM users_name WHERE act = ?", $act;

  # update state
  if (IServ::DB::Do "SELECT 1 FROM scmc_userpasswords WHERE act = ?", $act)
  {
    IServ::DB::Do "UPDATE scmc_userpasswords SET password = false WHERE act = ?", $act;
  } else
  {
    IServ::DB::Do "INSERT INTO scmc_userpasswords (act, password) VALUES (?, false)", $act;
  }

  # workaround for umlaut issues
  my $text = "Benutzerpasswort von $fullname gelöscht";
  my $out = encode "UTF-8", $text;
  print "$out.\n";
  my %row;
  $row{module} = "School Certificate Manager Connector";
  Stsbl::IServ::Log::log_store $out, %row;
}

1;
