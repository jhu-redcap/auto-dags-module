<?php
namespace Vanderbilt\AutoDAGsExternalModule;

class AutoDAGsExternalModule extends \ExternalModules\AbstractExternalModule
{
	// Constant used to separate the label and value in group names
	const LABEL_VALUE_SEPARATOR = ' - ';
	
	// Cache for group info to improve performance and avoid issues with REDCap::getGroupNames()
	private $groupsByID;
	
	/**
	 * This function is triggered when a record is saved in REDCap.
	 * It assigns a Data Access Group (DAG) based on a specific field's value.
	 */
	function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
	{
		try {
			// Get the project settings
			$dagFieldName = $this->getProjectSetting('dag-field');
			
			if (empty($dagFieldName)) {
				return; // Exit if no DAG field is set in the project settings
			}
			
			$currInstrumentFields = \REDCap::getFieldNames($instrument);    // Get the fields in the current instrument being saved
			
			// Check if the DAG field is part of the current instrument or if the setting to apply to all instruments is enabled
			if (in_array($dagFieldName, $currInstrumentFields) or !($this->getProjectSetting('curr-instr-only'))) {
				// Set the DAG based on the field's value
				$this->setDAGFromField($project_id, $record, $group_id, $dagFieldName);
			}
		} catch (\Exception $e) {
			// Log any errors that occur during the process
			$this->log("Error in redcap_save_record: " . $e->getMessage());
		}
	}   // end redcap_save_record
	
	/**
	 * Sets the Data Access Group (DAG) for a record based on the value of a specific field.
	 */
	function setDAGFromField($project_id, $record, $group_id, $dagFieldName)
	{
		try {
			// Convert the group ID to an integer if it exists, otherwise keep it as null
			$currentGroupId = !is_null($group_id) ? intval($group_id) : $group_id;
			$valueLabelFlag = $this->getProjectSetting('value-label-flag') ?? '1'; // match using the field's: 0 = value, 1 = label
			$dagAssignType = $this->getProjectSetting('dag-assign-type') ?? '1';   // match to DAG table's: 0 = id, 1 = name
			$createDagFlag = $this->getProjectSetting('create-dag-flag') ?? '1';   // If matching DAG does not exist: 0 = do nothing, 1 = create
			$recordIdFieldName = \REDCap::getRecordIdField();
			$alertemail = $this->getProjectSetting('email-notification') ?? 'msherm12@jh.edu';
			// Fetch the value of the DAG field for the given record
			$data = json_decode(\REDCap::getData($project_id, 'json', [$record], [$recordIdFieldName, $dagFieldName]))[0];
			if (!$data) {
				// Throw an exception if data retrieval fails
				throw new \Exception("Failed to retrieve data for record $record.");
			}
			//\REDCAP::email('msherm12@jh.edu','redcap@jh.edu','data for record '.$record,json_encode($data));
			// Get the value of the DAG field
			$fieldValue = $data->$dagFieldName;
			
			if (empty($fieldValue)) { // If the field value is empty, no group will be assigned
				$groupId = null;
				\REDCAP::email($alertemail,'redcap@jh.edu','empty data for record '.$record,json_encode($data->$dagFieldName));
			} else {
				$fieldLabel = $this->getChoiceLabels($dagFieldName)[$fieldValue];
				if ($valueLabelFlag == '1') { //use field label
					$FieldValueUse = $fieldLabel;
				}
				else{  //use field value
					$FieldValueUse = $fieldValue;
				}
				
				$verifyDagExists = $this->verifyDAGExists($FieldValueUse);
				//\REDCAP::email('msherm12@jh.edu','redcap@jh.edu','verifyDagExists for record '.$record,$verifyDagExists);
				if(!$verifyDagExists){
					//$groupId = $this->createDAG($project_id, $record, $FieldValueUse, $createDagFlag);
					//\REDCAP::email('msherm12@jh.edu','redcap@jh.edu','DAG Alert','DAG needs to be created for record '.$record);
					return;
				}
				
				$setdagflag = $this->setDAGValue($record,$currentGroupId,$FieldValueUse,$fieldLabel);
				if(!$setdagflag){
					\REDCAP::email($alertemail,'redcap@jh.edu','DAG Alert','DAG needs to be created for record '.$record.'currentGroupId: '.$currentGroupId.'FieldValueUse: '.$FieldValueUse.'fieldLabel: '.$fieldLabel);
				}
				return;
				
			}   // end else empty($fieldValue)
		} catch (\Exception $e) {
			// Log any errors that occur during the process
			$this->log("Error in setDAGFromField: " . $e->getMessage());
		}
	}   // end setDAGFromField
	private function verifyDAGExists($fieldValue)
{
	try {
		// Fetch all group names
		$dagGroups = \REDCap::getGroupNames();
		
		if (empty($dagGroups) || !is_array($dagGroups)) {
			// No DAG groups available or unexpected response
			return false;
		}
		
		// Determine how we are matching DAGs
		$dagAssignType = $this->getProjectSetting('dag-assign-type') ?? '1';
		
		// Use DAG name
		if ($dagAssignType == '1') {
			// Check if the provided value is in the array of group names
			$dagExists = in_array($fieldValue, $dagGroups, true);
		}
		// Use field value as group ID
		else {
			// Check if the provided value is a valid key in the group array
			$dagExists = array_key_exists($fieldValue, $dagGroups);
		}
		
		// Log an email for debugging if needed (but better to use logging here)
		//\REDCAP::email($alertemail, 'redcap@jh.edu', 'DAG Verification Result', 'DAG Assign Type: ' . $dagAssignType . "\nField Value: " . $fieldValue . "\nResult: " . ($dagExists ? 'Found' : 'Not Found'));
		
		return $dagExists;
	} catch (\Exception $e) {
		// Log any errors that occur during the process
		$this->log("Error in verifyDAGExists: " . $e->getMessage());
		return false;
	}
}
	private function setDAGValue($record, $currentGroupId, $fieldValueUse)
{
	try {
		// Fetch the setting to determine how to assign DAGs
		$dagAssignType = $this->getProjectSetting('dag-assign-type') ?? '1';
		
		// Fetch all group names and IDs
		$dagGroups = \REDCap::getGroupNames();
		
		if (empty($dagGroups) || !is_array($dagGroups)) {
			// No DAG groups available or unexpected response
			return false;
		}
		
		// Initialize variable for groupId to assign
		$groupIdToAssign = null;
		
		if ($dagAssignType == '1') {
			// Use DAG name to find the corresponding group ID
			$groupIdToAssign = array_search($fieldValueUse, $dagGroups, true);
			
			if ($groupIdToAssign === false) {
				// If no matching group name found, return false
				$this->log("DAG name '{$fieldValueUse}' not found in existing groups.");
				return false;
			}
		} else {
			// Use field value directly as the group ID
			$groupIdToAssign = $fieldValueUse;
			
			// Verify if the provided groupId is valid
			if (!array_key_exists($groupIdToAssign, $dagGroups)) {
				$this->log("DAG ID '{$groupIdToAssign}' is not valid.");
				return false;
			}
		}
		
		// If the current group ID is different from the target group ID, set the new DAG value
		if ($currentGroupId !== $groupIdToAssign) {
			// Set the DAG for the record
			$this->setDAG($record, $groupIdToAssign);
		}
		
		// Optional: Log the assignment
		$this->log("DAG successfully assigned for record {$record}. Assigned Group ID: {$groupIdToAssign}");
		$this->log("Attempting to update calculated fields for record {$record}.");
		$recalc_updates = \Calculate::saveCalcFields(array($record));
		$this->log("Calculations updated for record {$record}.");
		return true;
		
	} catch (\Exception $e) {
		// Log any errors that occur during the process
		$this->log("Error in setDAGValue: " . $e->getMessage());
		return false;
	}
}
	
	
	private function createNewDAG($groupName)
{
	$dagAssignType = $this->getProjectSetting('dag-assign-type') ?? '1';
	$createDAGFlag = $this->getProjectSetting('create-dag-flag') ?? '1';
	if ($dagAssignType == '1')
	{ //use DAG name
		// If the DAG does not exist, create it and retrieve the group ID
		$groupId = $this->createDAG($groupName);
	}
	else{  //use field value
	
	}
	// Check if the group ID is valid (returns false if invalid)
	return $groupId !== false;
	
}
	/**
	 * Retrieves the Data Access Group (DAG) ID and name associated with a specific field value.
	 *
	 * @param string $value The field value to search for in existing DAG names.
	 * @return array An array containing the group ID and group name, or null if not found.
	 */
	private function getDAGInfoForFieldValue($value): array
{
	
	try {
		// If the group information hasn't been cached yet, fetch and cache it
		if (!isset($this->groupsByID)) {
			$this->groupsByID = \REDCap::getGroupNames();
			
		}
		
		// Iterate through the cached group names to find a match for the field value
		foreach ($this->groupsByID as $groupId => $groupName) {
			// Find the last occurrence of the separator in the group name
			$lastSeparatorIndex = strrpos($groupName, self::LABEL_VALUE_SEPARATOR);
			// Extract the field value from the group name
			$associatedFieldValue = substr($groupName, $lastSeparatorIndex + strlen(self::LABEL_VALUE_SEPARATOR));
			
			// If the field value matches, return the group ID and name
			if ($associatedFieldValue == $value) {
				return [$groupId, $groupName];
			}
		}
		
		// If no matching DAG is found, return null
		return [null, null];
	} catch (\Exception $e) {
		// Log any errors that occur during the process
		$this->log("Error in getDAGInfoForFieldValue: " . $e->getMessage());
		return [null, null];
	}
}   // end getDAGInfoForFieldValue

}
