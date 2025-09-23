(function(wp) {
    const { addFilter } = wp.hooks;
    const { InspectorControls } = wp.blockEditor || wp.editor;
    const { PanelBody, SelectControl, TextControl } = wp.components;
    const { Fragment, createElement: el } = wp.element;
    const { useSelect, useDispatch } = wp.data;

    /**
     * Generate a stable restrictionId
     */
    function generateRestrictionId() {
        return 'r-' + Date.now().toString(36) + Math.random().toString(36).substr(2, 5);
    }

    /**
     * Find a rule in the array by restrictionId
     */
    function findRuleIndex(rulesArray, restrictionId) {
        return rulesArray.findIndex(function(rule) {
            return rule.restrictionId === restrictionId;
        });
    }

    /**
     * Add restrictionId attribute to all blocks
     */
    const addRestrictionIdAttribute = function(settings) {
        // Add restrictionId to all block types
        if (!settings.attributes) {
            settings.attributes = {};
        }
        
        settings.attributes.restrictionId = {
            type: 'string',
            default: ''
        };
        
        return settings;
    };

    /**
     * HOC to add Block Restriction controls to the block inspector
     */
    const withBlockRestrictionControls = function(BlockEdit) {
        return function(props) {
            // Use the correct selector for post meta
            const postMeta = useSelect(function(select) {
                const editor = select('core/editor');
                return editor ? editor.getEditedPostAttribute('meta') || {} : {};
            }, []);
            
            const { editPost } = useDispatch('core/editor');

            // Generate restrictionId only if it doesn't exist
            if (!props.attributes.restrictionId) {
                const newId = generateRestrictionId();
                props.setAttributes({ restrictionId: newId });
                return el(BlockEdit, props); // Return early to avoid processing with empty ID
            }

            const restrictionId = props.attributes.restrictionId;

            // Ensure we have an array and handle the case where meta might be undefined
            let rulesArray = [];
            if (postMeta && Array.isArray(postMeta._codi_restrict_blocks)) {
                rulesArray = [...postMeta._codi_restrict_blocks];
            }

            // Find current block's rule
            const idx = findRuleIndex(rulesArray, restrictionId);
                            const currentRule = idx !== -1
                ? rulesArray[idx]
                : {
                    restrictionId: restrictionId,
                    who: '',
                    roles: [],
                    roles_forbidden: [],
                    type: 'hide',
                    action: ''
                };

            // Update or insert rule
            const updateRule = function(field, value) {
                const updatedRule = { ...currentRule, [field]: value };

                let newRulesArray = [...rulesArray];
                if (idx !== -1) {
                    newRulesArray[idx] = updatedRule;
                } else {
                    newRulesArray.push(updatedRule);
                }

                // Update the meta with the new rules array
                const newMeta = { 
                    ...postMeta, 
                    _codi_restrict_blocks: newRulesArray 
                };
                
                editPost({ meta: newMeta });
            };

            // Remove rule when restriction is disabled
            const removeRule = function() {
                if (idx !== -1) {
                    const newRulesArray = [...rulesArray];
                    newRulesArray.splice(idx, 1);
                    
                    const newMeta = { 
                        ...postMeta, 
                        _codi_restrict_blocks: newRulesArray 
                    };
                    
                    editPost({ meta: newMeta });
                }
            };

            return el(
                Fragment,
                null,
                el(BlockEdit, props),
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: 'Block Restriction', initialOpen: true },
                        el(SelectControl, {
                            label: 'Who can access this block?',
                            value: currentRule.who,
                            options: [
                                { label: 'Everyone (no restriction)', value: '' },
                                { label: 'Anonymous users only', value: 'anonymous' },
                                { label: 'Logged in users only', value: 'members' },
                                { label: 'Specific roles only', value: 'roles' }
                            ],
                            onChange: function(val) { 
                                if (val === '') {
                                    removeRule();
                                } else {
                                    updateRule('who', val); 
                                }
                            }
                        }),
                        currentRule.who === 'roles' && el(TextControl, {
                            label: 'User must have these roles',
                            help: 'Comma-separated list',
                            value: Array.isArray(currentRule.roles) ? currentRule.roles.join(', ') : '',
                            onChange: function(val) {
                                const roles = val ? val.split(',').map(function(r) { return r.trim(); }) : [];
                                updateRule('roles', roles);
                            }
                        }),
                        currentRule.who === 'roles' && el(TextControl, {
                            label: 'User must NOT have these roles',
                            help: 'Comma separated list',
                            value: Array.isArray(currentRule.roles_forbidden) ? currentRule.roles_forbidden.join(', ') : '',
                            onChange: function(val) {
                                const rolesForbidden = val ? val.split(',').map(function(r) { return r.trim(); }) : [];
                                updateRule('roles_forbidden', rolesForbidden);
                            }
                        }),
                        currentRule.who && currentRule.who !== '' && el(SelectControl, {
                            label: 'What happens to restricted users?',
                            value: currentRule.type || 'hide',
                            options: [
                                { label: 'Hide block', value: 'hide' },
                                { label: 'Show restriction message', value: 'message' }
                            ],
                            onChange: function(val) { updateRule('type', val); }
                        }),
                        currentRule.type === 'message' && currentRule.who && currentRule.who !== '' && el(TextControl, {
                            label: 'Custom Message (optional)',
                            help: 'Leave blank for default message',
                            value: currentRule.action || '',
                            onChange: function(val) { updateRule('action', val); }
                        })
                    )
                )
            );
        };
    };

    // Register the attribute for all blocks
    addFilter(
        'blocks.registerBlockType',
        'codi-restrict/add-restriction-attribute',
        addRestrictionIdAttribute
    );

    // Add the inspector controls
    addFilter(
        'editor.BlockEdit',
        'codi-restrict/with-inspector-controls',
        withBlockRestrictionControls
    );

})(window.wp);