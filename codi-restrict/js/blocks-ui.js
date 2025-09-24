(function(wp) {
    const { addFilter } = wp.hooks;
    const { InspectorControls } = wp.blockEditor || wp.editor;
    const { PanelBody, SelectControl, TextControl } = wp.components;
    const { Fragment, createElement: el } = wp.element;
    const { useSelect, useDispatch } = wp.data;
    const { useEffect, useState } = wp.element;

    // Global registry to track all restriction IDs across the session
    const restrictionIdRegistry = new Set();

	/**
	 * Check if we're currently editing a global template/template part
	 */
	function isGlobalTemplateContext() {
		// Check for Site Editor (FSE)
		if (wp.data.select('core/edit-site')) {
			return true;
		}
		
		// Check for template part editor
		const currentPostType = wp.data.select('core/editor')?.getCurrentPostType?.();
		if (currentPostType === 'wp_template' || currentPostType === 'wp_template_part') {
			return true;
		}
		
		// Check URL parameters for template editing
		const urlParams = new URLSearchParams(window.location.search);
		if (urlParams.get('postType') === 'wp_template_part' || 
			urlParams.get('postType') === 'wp_template') {
			return true;
		}
		
		return false;
	}

    /**
     * Generate a truly unique restrictionId
     */
    function generateRestrictionId() {
        let attempts = 0;
        let id;
        
        do {
            generateRestrictionId.counter = (generateRestrictionId.counter || 0) + 1;
            const timestamp = Date.now().toString(36);
            const counter = generateRestrictionId.counter.toString(36);
            const random = Math.random().toString(36).substr(2, 4);
            const sessionId = (window.wp?.data?.select('core/editor')?.getCurrentPostId() || 'unknown').toString(36);
            
            id = `r-${timestamp}-${counter}-${random}-${sessionId}`;
            attempts++;
            
            // Failsafe to prevent infinite loops
            if (attempts > 100) {
                console.warn('CodiRestrict: Unable to generate unique restriction ID after 100 attempts');
                break;
            }
        } while (restrictionIdRegistry.has(id));
        
        restrictionIdRegistry.add(id);
        return id;
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
     * Check if restrictionId is already used by another block
     */
    function isRestrictionIdInUse(restrictionId, currentClientId, blocks) {
        return blocks.some(function(block) {
            return block.clientId !== currentClientId && 
                   block.attributes.restrictionId === restrictionId;
        });
    }

    /**
     * Get all blocks flattened including nested blocks
     */
    function getAllBlocksFlattened(blocks) {
        let flatBlocks = [];
        blocks.forEach(function(block) {
            flatBlocks.push(block);
            if (block.innerBlocks && block.innerBlocks.length > 0) {
                flatBlocks = flatBlocks.concat(getAllBlocksFlattened(block.innerBlocks));
            }
        });
        return flatBlocks;
    }

    /**
     * Check if a restriction rule exists for this post
     */
    function hasRuleForRestrictionId(rulesArray, restrictionId) {
        return rulesArray.some(function(rule) {
            return rule.restrictionId === restrictionId;
        });
    }

    /**
     * Count how many blocks are using a specific restrictionId
     */
    function countBlocksUsingRestrictionId(restrictionId, blocks) {
        return blocks.filter(function(block) {
            return block.attributes.restrictionId === restrictionId;
        }).length;
    }

    /**
     * Add restrictionId attribute to all blocks
     */
    const addRestrictionIdAttribute = function(settings) {
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
            const [isProcessingId, setIsProcessingId] = useState(false);

            // Get post meta and all blocks
            const { postMeta, allBlocks } = useSelect(function(select) {
                const editor = select('core/editor');
                const blockEditor = select('core/block-editor') || select('core/editor');
                
                return {
                    postMeta: editor ? editor.getEditedPostAttribute('meta') || {} : {},
                    allBlocks: blockEditor ? blockEditor.getBlocks() : []
                };
            }, []);
            
            const { editPost } = useDispatch('core/editor');

            const flatBlocks = getAllBlocksFlattened(allBlocks);

            // Ensure we have a rules array
            let rulesArray = [];
            if (postMeta && Array.isArray(postMeta._codi_restrict_blocks)) {
                rulesArray = [...postMeta._codi_restrict_blocks];
            }

            // Handle restrictionId assignment and validation
            useEffect(function() {
                if (isProcessingId) return;

                const currentId = props.attributes.restrictionId;
                let needsNewId = false;
                let reason = '';

                // Check if we need a new ID
                if (!currentId) {
                    needsNewId = true;
                    reason = 'missing';
                } else if (isRestrictionIdInUse(currentId, props.clientId, flatBlocks)) {
                    // For duplicates, only the "newer" block (by clientId) should get a new ID
                    // This ensures the original block keeps its ID and rule
                    const blocksWithSameId = flatBlocks.filter(function(block) {
                        return block.attributes.restrictionId === currentId;
                    });
                    
                    // Sort by clientId to get deterministic behavior
                    const sortedBlocks = blocksWithSameId.sort(function(a, b) {
                        return a.clientId.localeCompare(b.clientId);
                    });
                    
                    // Only blocks that are NOT the first one (original) need new IDs
                    const isOriginalBlock = sortedBlocks[0].clientId === props.clientId;
                    
                    if (!isOriginalBlock) {
                        needsNewId = true;
                        reason = 'duplicate';
                    }
                } else if (!hasRuleForRestrictionId(rulesArray, currentId) && currentId.includes('-unknown-')) {
                    // This might be a copied block from another post
                    needsNewId = true;
                    reason = 'orphaned';
                }

                if (needsNewId) {
                    setIsProcessingId(true);
                    const newId = generateRestrictionId();
                    
                    // Copy existing rule if this was a duplication
                    if (reason === 'duplicate' && currentId) {
                        const existingRuleIndex = findRuleIndex(rulesArray, currentId);
                        if (existingRuleIndex !== -1) {
                            const copiedRule = { 
                                ...rulesArray[existingRuleIndex], 
                                restrictionId: newId 
                            };
                            
                            const newRulesArray = [...rulesArray, copiedRule];
                            const newMeta = { 
                                ...postMeta, 
                                _codi_restrict_blocks: newRulesArray 
                            };
                            
                            editPost({ meta: newMeta });
                        }
                    }

                    props.setAttributes({ restrictionId: newId });
                    
                    // Reset processing flag after a brief delay
                    setTimeout(() => setIsProcessingId(false), 100);
                }
            }, [props.attributes.restrictionId, props.clientId, flatBlocks.length, rulesArray.length]);

            // Don't render controls while processing ID changes
            if (isProcessingId || !props.attributes.restrictionId) {
                return el(BlockEdit, props);
            }

            const restrictionId = props.attributes.restrictionId;

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

			// Don't show restriction controls in global templates
			if (isGlobalTemplateContext()) {
				return el(BlockEdit, props);
			}

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
                            help: 'Comma-separated list (e.g., editor, author)',
                            value: Array.isArray(currentRule.roles) ? currentRule.roles.join(', ') : (currentRule.roles || ''),
                            onChange: function(val) {
                                // If the value looks like it's still being typed (ends with comma or space), store as string
                                if (typeof val === 'string' && (val.endsWith(',') || val.endsWith(', ') || val.endsWith(' '))) {
                                    updateRule('roles', val);
                                } else {
                                    // Parse into array
                                    const roles = val ? val.split(',').map(function(r) { return r.trim(); }).filter(function(r) { return r; }) : [];
                                    updateRule('roles', roles);
                                }
                            }
                        }),
                        currentRule.who === 'roles' && el(TextControl, {
                            label: 'User must NOT have these roles',
                            help: 'Comma-separated list (e.g., subscriber)',
                            value: Array.isArray(currentRule.roles_forbidden) ? currentRule.roles_forbidden.join(', ') : (currentRule.roles_forbidden || ''),
                            onChange: function(val) {
                                // If the value looks like it's still being typed (ends with comma or space), store as string
                                if (typeof val === 'string' && (val.endsWith(',') || val.endsWith(', ') || val.endsWith(' '))) {
                                    updateRule('roles_forbidden', val);
                                } else {
                                    // Parse into array
                                    const rolesForbidden = val ? val.split(',').map(function(r) { return r.trim(); }).filter(function(r) { return r; }) : [];
                                    updateRule('roles_forbidden', rolesForbidden);
                                }
                            }
                        }),
                        currentRule.who && currentRule.who !== '' && el(SelectControl, {
                            label: 'What happens to restricted users?',
                            value: currentRule.type || 'hide',
                            options: [
                                { label: 'Hide block completely', value: 'hide' },
                                { label: 'Show restriction message', value: 'message' }
                            ],
                            onChange: function(val) { updateRule('type', val); }
                        }),
                        currentRule.type === 'message' && currentRule.who && currentRule.who !== '' && el(TextControl, {
                            label: 'Custom message (optional)',
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