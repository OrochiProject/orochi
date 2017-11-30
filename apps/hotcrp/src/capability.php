<?php
// capability.php -- HotCRP capability management
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CapabilityManager {

    private $dblink;
    private $prefix;

    public function __construct($dblink, $prefix) {
        $this->dblink = $dblink;
        $this->prefix = $prefix;
    }

    public function create($capabilityType, $options = array()) {
        global $Opt;
        $contactId = defval($options, "contactId", 0);
        if (!$contactId && ($user = @$options["user"]))
            $contactId = $this->prefix === "U" ? $user->contactDbId : $user->contactId;
        $paperId = defval($options, "paperId", 0);
        $timeExpires = defval($options, "timeExpires", cheng_time() + 259200);
        $data = defval($options, "data");
        $capid = false;

        for ($tries = 0; !$capid && $tries < 4; ++$tries)
            if (($salt = hotcrp_random_bytes(16)) !== false) {
                Dbl::ql($this->dblink, "insert into Capability set capabilityType=$capabilityType, contactId=?, paperId=?, timeExpires=?, salt=?, data=?",
                        $contactId, $paperId, $timeExpires, $salt, $data);
                //$capid = $this->dblink->insert_id;
                $capid = cheng_mysqli_insert_id($this->dblink);
            }

        if (!$capid)
            return false;
        return $this->prefix . "1"
            . str_replace(array("+", "/", "="),
                          array("-a", "-b", ""), base64_encode($salt));
    }

    public function check($capabilityText) {
        if (substr($capabilityText, 0, strlen($this->prefix) + 1) !== $this->prefix . "1")
            return false;
        $value = base64_decode(str_replace(array("-a", "-b", "-", "_"), // -, _ obsolete
                                           array("+", "/", "+", "/"),
                                           substr($capabilityText, strlen($this->prefix) + 1)));
        if (strlen($value) >= 16
            && ($result = Dbl::ql($this->dblink, "select capabilityId, capabilityType, contactId, paperId, timeExpires, salt, data from Capability where salt=?", $value))
            && ($row = Dbl::fetch_first_object($result))
            && ($row->timeExpires == 0 || $row->timeExpires >= cheng_time()))
            return $row;
        else
            return false;
    }

    public function delete($capdata) {
        if ($capdata)
            Dbl::ql($this->dblink, "delete from Capability where capabilityId=" . $capdata->capabilityId);
    }

}
